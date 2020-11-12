<?php

/**
 * Synchronization handler that stores changes to roundcube database.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;
use carddav;

class SyncHandlerRoundcube implements SyncHandler
{
    /** @var bool hadErrors If true, errors that did not cause the sync to be aborted occurred. */
    public $hadErrors = false;

    /** @var Addressbook Object of the addressbook that is being synchronized */
    private $rcAbook;

    /** @var array Maps URIs to an associative array containing etag and (database) id */
    private $localCards = [];

    /** @var array Maps URIs of KIND=group cards to an associative array containing etag and (database) id */
    private $localGrpCards = [];

    /** @var string[] List of DB ids of CATEGORIES-type groups at the time the sync is started.
     *                Note: If a contact's existing memberships to such groups are determined, this is sufficient and
     *                      we do not have to add new CATEGORIES-type groups created during the sync to this list.
     */
    private $localCatGrpIds = [];

    /** @var array records VCard-type groups that need to be updated (array of arrays with keys etag, uri, vcard) */
    private $grpCardsToUpdate = [];

    /** @var string[] a list of group IDs that may be cleared from the DB if empty and CATEGORIES-type */
    private $clearGroupCandidates = [];

    /** @var DataConversion $dataConverter to convert between VCard and roundcube's representation of contacts. */
    private $dataConverter;

    /** @var AddressbookCollection $davAbook */
    private $davAbook;

    /** @var Database $db Database access object */
    private $db;

    /** @var LoggerInterface $logger Log object */
    private $logger;

    public function __construct(
        Addressbook $rcAbook,
        Database $db,
        LoggerInterface $logger,
        DataConversion $dataConverter,
        AddressbookCollection $davAbook
    ) {
        $this->logger = $logger;
        $this->db = $db;
        $this->rcAbook = $rcAbook;
        $this->dataConverter = $dataConverter;
        $this->davAbook = $davAbook;

        $abookId = $this->rcAbook->getId();

        // determine existing local contact URIs and ETAGs
        $contacts = $db->get($abookId, 'id,uri,etag', 'contacts', false, 'abook_id');
        foreach ($contacts as $contact) {
            $this->localCards[$contact['uri']] = $contact;
        }

        // determine existing local group URIs and ETAGs
        $groups = $db->get($abookId, 'id,uri,etag', 'groups', false, 'abook_id');
        foreach ($groups as $group) {
            if (isset($group['uri'])) { // these are groups defined by a KIND=group VCard
                $this->localGrpCards[$group['uri']] = $group;
            } else { // these are groups derived from CATEGORIES in the contact VCards
                $this->localCatGrpIds[] = (string) $group['id'];
            }
        }
    }

    /**
     * Process a card reported as changed by the server (includes new cards).
     *
     * Cards of individuals are processed immediately, updating the database. Cards of KIND=group are recorded and
     * processed after all individuals have been processed in finalizeSync(). This is because these group cards may
     * reference individuals, and we need to have all of them in the local DB before processing the groups.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param ?VCard $card The card as a Sabre VCard object. Null if the address data could not be retrieved/parsed.
     */
    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        // in case a card has an error, we continue syncing the rest
        if (!isset($card)) {
            $this->hadErrors = true;
            $this->logger->error("Card $uri changed, but error in retrieving address data (card ignored)");
            return;
        }

        if (strcasecmp((string) $card->{"X-ADDRESSBOOKSERVER-KIND"}, "group") === 0) {
            $this->grpCardsToUpdate[] = [ "vcard" => $card, "etag" => $etag, "uri" => $uri ];
        } else {
            $this->updateContactCard($uri, $etag, $card);
        }
    }

    /**
     * Process a card reported as deleted the server.
     *
     * This function immediately updates the state in the database for both contact and group cards, deleting all
     * membership relations between contacts/groups if either side is deleted. If a CATEGORIES-type groups loses a
     * member during this process, we record it as a candidate that is deleted by finalizeSync() in case the group is
     * empty at the end of the sync.
     *
     * It is quite common for servers to report cards as deleted that were never seen by this client, for example when a
     * card was added and deleted again between two syncs. Thus, we must not react hard on such situations (we log a
     * notice).
     *
     * It is also possible that a URI is both reported as deleted and changed. This can happen if a URI was deleted and
     * a new object was created at the same URI. The Sync service will report all deleted objects first for this reason,
     * so we don't have to care about it here. However, we must clean up the local state before the
     * addressObjectChanged() function is called with a URI that was deleted, so it does not wrongfully assume a
     * connection between the deleted and the new card (and try to update the deleted card that no longer exists in the
     * DB).
     *
     * @param string $uri URI of the card
     */
    public function addressObjectDeleted(string $uri): void
    {
        $this->logger->info("Deleted card $uri");
        $db = $this->db;

        if (isset($this->localCards[$uri]["id"])) {
            // delete contact
            $dbid = $this->localCards[$uri]["id"];

            // CATEGORIES-type groups may become empty as a user is deleted and should then be deleted as well. Record
            // what groups the user belonged to.
            if (!empty($this->localCatGrpIds)) {
                $group_ids = array_column(
                    $db->get(
                        $dbid,
                        "group_id",
                        "group_user",
                        false,
                        "contact_id",
                        [ "group_id" => $this->localCatGrpIds ]
                    ),
                    "group_id"
                );
                $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $group_ids);

                $db->delete($dbid, "group_user", "contact_id");
            }
            $db->delete($dbid);

            // important: URI may be reported as deleted and then reused for new card.
            unset($this->localCards[$uri]);
        } elseif (isset($this->localGrpCards[$uri]["id"])) {
            // delete VCard-type group
            $dbid = $this->localGrpCards[$uri]["id"];
            $db->delete($dbid, "group_user", "group_id");
            $db->delete($dbid, "groups");

            // important: URI may be reported as deleted and then reused for new card.
            unset($this->localGrpCards[$uri]);
        } else {
            $this->logger->notice("Server reported deleted card $uri for that no DB entry exists");
        }
    }

    /**
     * Provides the current local cards and ETags to the Sync service.
     *
     * This is only requested by the Sync service in case it has to fall back to PROPFIND-based synchronization,
     * i.e. if sync-collection REPORT is not supported by the server or did not work.
     *
     * @return string[] Maps card URIs to ETags
     */
    public function getExistingVCardETags(): array
    {
        return array_column(
            array_merge($this->localCards, $this->localGrpCards),
            "etag",
            "uri"
        );
    }

    /**
     * Finalize the sychronization process.
     *
     * This function is called last by the Sync service after all changes have been reported. We use it to perform
     * delayed actions, namely the processing of changed group vcards and deletion of CATEGORIES-type groups that became
     * empty during this sync.
     */
    public function finalizeSync(): void
    {
        $db = $this->db;
        $abookId = $this->rcAbook->getId();

        // Now process all KIND=group type VCards that the server reported as changed
        foreach ($this->grpCardsToUpdate as $g) {
            $this->updateGroupCard($g["uri"], $g["etag"], $g["vcard"]);
        }

        // Delete all CATEGORIES-TYPE groups that had their last contacts deleted during this sync
        $group_ids = array_unique($this->clearGroupCandidates);
        if (!empty($group_ids)) {
            try {
                $db->startTransaction(false);
                $group_ids_nonempty = array_column(
                    $db->get($group_ids, "group_id", "group_user", false, "group_id"),
                    "group_id"
                );

                $group_ids_empty = array_diff($group_ids, $group_ids_nonempty);
                if (!empty($group_ids_empty)) {
                    $this->logger->info("Delete empty CATEGORIES-type groups: " . implode(",", $group_ids_empty));
                    $db->delete($group_ids_empty, "groups", "id", [ "uri" => null, "abook_id" => $abookId ]);
                }
                $db->endTransaction();
            } catch (\Exception $e) {
                $this->hadErrors = true;
                $this->logger->error("Failed to delete emptied CATEGORIES-type groups: " . $e->getMessage());
                $db->rollbackTransaction();
            }
        }
    }

    /**
     * This function determines the group IDs of CATEGORIES-type groups the individual of the
     * given VCard belongs to. Groups are created if needed.
     *
     * @param VCard $card The VCard that describes the individual, including the CATEGORIES attribute
     * @return string[] An array of DB ids of the CATEGORIES-type groups the user belongs to.
     */
    private function getCategoryTypeGroupsForUser(VCard $card): array
    {
        $abookId = $this->rcAbook->getId();

        // Determine CATEGORIES-type group ID (and create if needed) of the user
        $categories = [];
        if (isset($card->CATEGORIES)) {
            $categories = $card->CATEGORIES->getParts();
            // remove all whitespace categories
            Addressbook::stringsAddRemove($categories);
        }

        if (empty($categories)) {
            return [];
        }

        $db = $this->db;
        try {
            $db->startTransaction(false);
            $group_ids_by_name = array_column(
                $db->get($abookId, "id,name", "groups", false, "abook_id", ["uri" => null, "name" => $categories]),
                "id",
                "name"
            );

            foreach ($categories as $category) {
                if (!isset($group_ids_by_name[$category])) {
                    $gsave_data = [
                        'name' => $category,
                        'kind' => 'group'
                    ];
                    $dbid = $db->storeGroup($abookId, $gsave_data);
                    $group_ids_by_name[$category] = $dbid;
                }
            }
            $db->endTransaction();

            return array_values($group_ids_by_name);
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $this->logger->error("Failed to determine CATEGORIES-type groups for contact: " . $e->getMessage());
            $db->rollbackTransaction();
        }

        return [];
    }

    /**
     * Updates a KIND=individual VCard in the local DB.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param VCard $card The card as a Sabre VCard object.
     */
    private function updateContactCard(string $uri, string $etag, VCard $card): void
    {
        $abookId = $this->rcAbook->getId();
        $db = $this->db;

        // card may be changed during conversion, in particular inlining of the PHOTO
        [ 'save_data' => $save_data, 'vcf' => $card ] = $this->dataConverter->toRoundcube($card, $this->davAbook);
        $this->logger->info("Changed Individual $uri " . $save_data['name']);

        $dbid = $this->localCards[$uri]["id"] ?? null;
        if (isset($dbid)) {
            $dbid = (string) $dbid;
        }

        try {
            $db->startTransaction(false);
            $dbid = $db->storeContact($abookId, $etag, $uri, $card->serialize(), $save_data, $dbid);
            $db->endTransaction();

            // determine current assignments to CATGEGORIES-type groups
            $cur_group_ids = $this->getCategoryTypeGroupsForUser($card);

            $db->startTransaction(false);

            // Update membership to CATEGORIES-type groups
            $old_group_ids = empty($this->localCatGrpIds)
                ? []
                : array_column(
                    $db->get(
                        $dbid,
                        "group_id",
                        "group_user",
                        false,
                        "contact_id",
                        [ "group_id" => $this->localCatGrpIds ]
                    ),
                    "group_id"
                );

            $del_group_ids = array_diff($old_group_ids, $cur_group_ids);
            if (!empty($del_group_ids)) {
                // CATEGORIES-type groups may become empty when members are removed. Record those the user belonged to.
                $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $del_group_ids);
                // remove contact from CATEGORIES-type groups he no longer belongs to
                $db->delete($dbid, 'group_user', 'contact_id', [ "group_id" => $del_group_ids ]);
            }

            // add contact to CATEGORIES-type groups he newly belongs to
            $add_group_ids = array_diff($cur_group_ids, $old_group_ids);
            foreach ($add_group_ids as $group_id) {
                $db->insert("group_user", ["contact_id", "group_id"], [$dbid, $group_id]);
            }

            $db->endTransaction();
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $this->logger->error("Failed to process changed card $uri: " . $e->getMessage());
            $db->rollbackTransaction();
        }
    }

    /**
     * Updates a KIND=group VCard in the local DB.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param VCard $card The card as a Sabre VCard object.
     */
    private function updateGroupCard(string $uri, string $etag, VCard $card): void
    {
        $db = $this->db;
        $dbh = $db->getDbHandle();
        $abookId = $this->rcAbook->getId();

        // card may be changed during conversion, in particular inlining of the PHOTO
        [ 'save_data' => $save_data, 'vcf' => $card ] = $this->dataConverter->toRoundcube($card, $this->davAbook);

        $dbid = $this->localGrpCards[$uri]["id"] ?? null;
        if (isset($dbid)) {
            $dbid = (string) $dbid;
        }

        $this->logger->info("Changed Group $uri " . $save_data['name']);

        // X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:51A7211B-358B-4996-90AD-016D25E77A6E
        $members = $card->{'X-ADDRESSBOOKSERVER-MEMBER'} ?? [];
        $cuids = [];

        $this->logger->debug("Group $uri has " . count($members) . " members");
        foreach ($members as $mbr) {
            $mbrc = explode(':', (string) $mbr);
            if (count($mbrc) != 3 || $mbrc[0] !== 'urn' || $mbrc[1] !== 'uuid') {
                $this->logger->warning("don't know how to interpret group membership: $mbr");
                continue;
            }
            $cuids[] = $dbh->quote($mbrc[2]);
        }

        try {
            $db->startTransaction(false);
            // store group card
            $dbid = $db->storeGroup($abookId, $save_data, $dbid, $etag, $uri, $card->serialize());

            // delete current group members (will be reinserted if needed below)
            $db->delete($dbid, 'group_user', 'group_id');

            // Update member assignments
            if (count($cuids) > 0) {
                $sql_result = $dbh->query('INSERT INTO ' .
                    $dbh->table_name('carddav_group_user') .
                    ' (group_id,contact_id) SELECT ?,id from ' .
                    $dbh->table_name('carddav_contacts') .
                    ' WHERE abook_id=? AND cuid IN (' . implode(',', $cuids) . ')', $dbid, $abookId);
                $this->logger->debug("Added " . $dbh->affected_rows($sql_result) . " contacts to group $dbid");
            }
            $db->endTransaction();
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $this->logger->error("Failed to update group $dbid: " . $e->getMessage());
            $db->rollbackTransaction();
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
