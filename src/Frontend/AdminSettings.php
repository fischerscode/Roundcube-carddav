<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavAddressbook4Roundcube\{Config, RoundcubeLogger};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * Represents the administrative settings of the plugin.
 *
 * @psalm-type PasswordStoreScheme = 'plain' | 'base64' | 'des_key' | 'encrypted'
 * @psalm-type ConfigurablePresetAttr = 'name'|'username'|'password'|'url'|'accountActive'|'rediscover_time'
 *                                      'active'|'refresh_time'|'use_categories'
 *
 * @psalm-type PresetExtraAbook = array{
 *     url: string,
 *     active: bool,
 *     readonly: bool,
 *     refresh_time: int,
 *     use_categories: bool,
 *     fixed: list<ConfigurablePresetAttr>,
 *     require_always: list<string>,
 * }
 * @psalm-type Preset = array{
 *     name: string,
 *     username: string,
 *     password: string,
 *     url: ?string,
 *     accountActive: bool,
 *     rediscover_time: int,
 *     hide: bool,
 *     active: bool,
 *     readonly: bool,
 *     refresh_time: int,
 *     use_categories: bool,
 *     fixed: list<ConfigurablePresetAttr>,
 *     require_always: list<string>,
 *     extra_addressbooks: array<string, PresetExtraAbook>,
 * }
 *
 * @psalm-type SettingSpecification=array{'timestr'|'string'|'bool'|'string[]'|'skip', bool, int|string|bool|array|null}
 *
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type AccountSettings from AddressbookManager
 * @psalm-import-type AbookSettings from AddressbookManager
 */
class AdminSettings
{
    /** @var list<PasswordStoreScheme> List of supported password store schemes */
    public const PWSTORE_SCHEMES = [ 'plain', 'base64', 'des_key', 'encrypted' ];

    /**
     * @var array<string, SettingSpecification> PRESET_SETTINGS_EXTRA_ABOOK
     *   This describes the valid attributes in a preset configuration of an extra addressbook (non-discovered), their
     *   data type, whether they are mandatory to be specified by the admin, and the default value for optional
     *   attributes.
     */
    private const PRESET_SETTINGS_EXTRA_ABOOK = [
        // type, mandatory, default value
        'url' => [ 'string', true, null ],
        'active' => [ 'bool', false, true ],
        'readonly' => [ 'bool', false, false, null ],
        'refresh_time' => [ 'timestr', false, '1:0:0' ],
        'use_categories' => [ 'bool', false, true ],
        'fixed' => [ 'string[]', false, [], null ],
        'require_always' => [ 'string[]', false, [], null ],
    ];

    /**
     * @var array<string, SettingSpecification> PRESET_SETTINGS
     *   This describes the valid attributes in a preset configuration, their data type, whether they are mandatory to
     *   be specified by the admin, and the default value for optional attributes.
     */
    private const PRESET_SETTINGS = [
        // type, mandatory, default value
        'name' => [ 'string', true, null ],
        'username' => [ 'string', false, '' ],
        'password' => [ 'string', false, '' ],
        'url' => [ 'string', false, null ],
        'accountActive' => [ 'bool', false, true ],
        'rediscover_time' => [ 'timestr', false, '24:0:0' ],
        'hide' => [ 'bool', false, false ],
        'extra_addressbooks' => [ 'skip', false, null ],
    ] + self::PRESET_SETTINGS_EXTRA_ABOOK;

    /**
     * @var array<ConfigurablePresetAttr, array{'account'|'addressbook', string}> PRESET_ATTR_DBMAP
     *   This contains the attributes that can be automatically updated from an admin preset if the admin configured
     *   them as fixed. It maps the attribute name from the preset to the DB object type (account or addressbook) and
     *   the DB column name.
     */
    private const PRESET_ATTR_DBMAP = [
        'name'            => ['account','name'],
        'username'        => ['account','username'],
        'password'        => ['account','password'],
        'url'             => ['account','url'], // addressbook URL cannot be updated, only discovery URI
        'accountActive'   => ['account','active'],
        'rediscover_time' => ['account','rediscover_time'],
        'active'          => ['addressbook','active'],
        'refresh_time'    => ['addressbook','refresh_time'],
        'use_categories'  => ['addressbook','use_categories']
    ];

    /**
     * @var PasswordStoreScheme encryption scheme
     * @readonly
     */
    public $pwStoreScheme = 'encrypted';

    /**
     * @var bool Global preference "fixed"
     * @readonly
     */
    public $forbidCustomAddressbooks = false;

    /**
     * @var bool Global preference "hide_preferences"
     * @readonly
     */
    public $hidePreferences = false;

    /**
     * @var array<string, Preset> Presets from config.inc.php
     */
    private $presets = [];

    /**
     * Initializes AdminSettings from a config.inc.php file, using default values if that file is not available.
     * @param string $configfile Path of the config.inc.php file to load.
     */
    public function __construct(string $configfile)
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $httpLogger = $infra->httpLogger();

        $prefs = [];
        if (file_exists($configfile)) {
            include($configfile);
        }

        // Extract global preferences
        if (isset($prefs['_GLOBAL']['pwstore_scheme']) && is_string($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];

            if (in_array($scheme, self::PWSTORE_SCHEMES)) {
                /** @var PasswordStoreScheme $scheme */
                $this->pwStoreScheme = $scheme;
            }
        }

        $this->forbidCustomAddressbooks = ($prefs['_GLOBAL']['fixed'] ?? false) ? true : false;
        $this->hidePreferences = ($prefs['_GLOBAL']['hide_preferences'] ?? false) ? true : false;

        foreach (['loglevel' => $logger, 'loglevel_http' => $httpLogger] as $setting => $logger) {
            if (isset($prefs['_GLOBAL'][$setting]) && is_string($prefs['_GLOBAL'][$setting])) {
                if ($logger instanceof RoundcubeLogger) {
                    $logger->setLogLevel($prefs['_GLOBAL'][$setting]);
                }
            }
        }

        // Store presets
        foreach ($prefs as $presetName => $preset) {
            // _GLOBAL contains plugin configuration not related to an addressbook preset - skip
            if ($presetName === '_GLOBAL') {
                continue;
            }

            if (!is_string($presetName) || strlen($presetName) == 0) {
                $logger->error("A preset key must be a non-empty string - ignoring preset!");
                continue;
            }

            if (!is_array($preset)) {
                $logger->error("A preset definition must be an array of settings - ignoring preset $presetName!");
                continue;
            }

            $this->addPreset($presetName, $preset);
        }
    }

    /**
     * Returns the preset with the given name.
     *
     * Optionally, the URL of a manually added addressbook may be given. In this case, the returned preset will contain
     * the values of that specific addressbook instead of those for auto-discovered addressbooks.
     *
     * @return Preset
     */
    public function getPreset(string $presetName, ?string $xabookUrl = null): array
    {
        if (!isset($this->presets[$presetName])) {
            throw new \Exception("Query for undefined preset $presetName");
        }

        $preset = $this->presets[$presetName];

        if (isset($xabookUrl) && isset($preset['extra_addressbooks'][$xabookUrl])) {
            /**
             * @psalm-var Preset $preset psalm assumes that extra keys (e.g. hide) may be present in the xabook preset
             *            with unknown type, but this is not the case
             */
            $preset = $preset['extra_addressbooks'][$xabookUrl] + $preset;
        }

        return $preset;
    }

    /**
     * Creates / updates / deletes preset addressbooks.
     */
    public function initPresets(AddressbookManager $abMgr): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $userId = (string) $_SESSION['user_id'];

            // Get all existing accounts of this user that have been created from presets
            $accountIds = $abMgr->getAccountIds(false, true);
            $existingPresets = [];
            foreach ($accountIds as $accountId) {
                $account = $abMgr->getAccountConfig($accountId);
                /** @psalm-var string $presetName Not null because filtered by getAccountIds() */
                $presetName = $account['presetname'];
                $existingPresets[$presetName] = $accountId;
            }

            // Walk over the current presets configured by the admin and add, update or delete addressbooks
            foreach ($this->presets as $presetName => $preset) {
                if (isset($existingPresets[$presetName])) {
                    $accountId = $existingPresets[$presetName];

                    // Update the extra addressbooks with the current set of the admin
                    // TODO

                    // Update the fixed account/addressbook settings with the current admin values
                    $this->updatePresetSettings($presetName, $accountId, $abMgr);
                } else {
                    // If no account exists yet, add a new account for the preset
                    $logger->info("Adding preset $presetName for user $userId");

                    try {
                        // store account
                        $account = $this->makeDbObjFromPreset('account', $preset);
                        $account['presetname'] = $presetName;
                        $abookTmpl = $this->makeDbObjFromPreset('addressbook', $preset);
                        $abookTmpl["account_id"] = $abMgr->insertAccount($account);

                        // Auto-discovery if discovery URI is set
                        if (isset($preset['url'])) {
                            $username = Utils::replacePlaceholdersUsername($preset['username']);
                            $password = Utils::replacePlaceholdersPassword($preset['password']);
                            $discoveryUrl = Utils::replacePlaceholdersUrl($preset['url']);
                            $account = Config::makeAccount($discoveryUrl, $username, $password, null);
                            $discover = $infra->makeDiscoveryService();
                            $abooks = $discover->discoverAddressbooks($account);

                            // store discovered addressbooks
                            foreach ($abooks as $abook) {
                                $abookTmpl['name'] = $abook->getName();
                                $abookTmpl['url'] = $abook->getUri();
                                $abookTmpl['discovered'] = true;
                                $abMgr->insertAddressbook($abookTmpl);
                            }
                        }

                        // 2) Create / delete the extra addressbooks for this preset
                        // TODO
                    } catch (\Exception $e) {
                        $logger->error("Error adding preset $presetName for user $userId {$e->getMessage()}");
                    }
                }

                unset($existingPresets[$presetName]);
            }

            // delete existing preset addressbooks that were removed by admin
            foreach ($existingPresets as $presetName => $accountId) {
                $logger->info("Deleting preset $presetName for user $userId");
                $abMgr->deleteAccount($accountId); // also deletes the addressbooks
            }
        } catch (\Exception $e) {
            $logger->error("Error initializing preconfigured addressbooks: {$e->getMessage()}");
        }
    }

    /**
     * Updates the fixed fields of account and addressbooks derived from a preset with the current admin settings.
     *
     * Only fixed fields are updated, as non-fixed fields may have been changed by the user.
     *
     * @param AddressbookManager $abMgr The addressbook manager.
     */
    private function updatePresetSettings(string $presetName, string $accountId, AddressbookManager $abMgr): void
    {
        $account = $abMgr->getAccountConfig($accountId);
        // decrypt password so that the comparison works
        $account['password'] = Utils::decryptPassword($account['password']);
        $this->updatePresetObject($account, 'account', $presetName, $abMgr);

        $abooks = $abMgr->getAddressbookConfigsForAccount($accountId);
        foreach ($abooks as $abook) {
            $this->updatePresetObject($abook, 'addressbook', $presetName, $abMgr);
        }
    }

    /**
     * Updates the fixed fields of one preset object (account or addressbook) with the current admin settings.
     *
     * Only fixed fields are updated, as non-fixed fields may have been changed by the user.
     *
     * @param FullAbookRow | FullAccountRow $obj
     * @param 'addressbook'|'account' $type
     */
    private function updatePresetObject(array $obj, string $type, string $presetName, AddressbookManager $abMgr): void
    {
        // extra addressbooks (discovered == 0) can have individual preset settings
        $xabookUrl = ($type == 'addressbook') ? $obj['url'] : null;
        $preset = $this->getPreset($presetName, $xabookUrl);

        // update only those attributes marked as fixed by the admin
        // otherwise there may be user changes that should not be destroyed
        $pa = [];
        foreach ($preset['fixed'] as $k) {
            if (isset($preset[$k]) && isset(self::PRESET_ATTR_DBMAP[$k])) {
                [ $attrObjType, $attrDbName ] = self::PRESET_ATTR_DBMAP[$k];

                if ($type == $attrObjType && isset($obj[$attrDbName]) && $obj[$attrDbName] != $preset[$k]) {
                    $pa[$attrDbName] = $preset[$k];
                }
            }
        }

        // only update if something changed
        if (!empty($pa)) {
            if ($type == 'account') {
                /** @psalm-var AccountSettings $pa */
                $abMgr->updateAccount($obj['id'], $pa);
            } else {
                /** @psalm-var AbookSettings $pa */
                $abMgr->updateAddressbook($obj['id'], $pa);
            }
        }
    }

    /**
     * Creates a DB object to insert from a preset.
     *
     * @param 'addressbook'|'account' $type
     * @param Preset $preset
     * @return AbookSettings | AccountSettings
     */
    private function makeDbObjFromPreset(string $type, array $preset): array
    {
        $result = [];

        foreach (self::PRESET_ATTR_DBMAP as $k => $spec) {
            [ $attrObjType, $attrDbName ] = $spec;
            if ($type == $attrObjType) {
                $result[$attrDbName] = $preset[$k];
            }
        }

        /** @psalm-var AbookSettings | AccountSettings $result */
        return $result;
    }

    /**
     * Adds the given preset from config.inc.php to $this->presets.
     */
    private function addPreset(string $presetName, array $preset): void
    {
        $logger = Config::inst()->logger();

        try {
            /** @psalm-var Preset Checked by parsePresetArray() */
            $result = $this->parsePresetArray(self::PRESET_SETTINGS, $preset);

            // Parse extra addressbooks
            $result['extra_addressbooks'] = [];
            if (isset($preset['extra_addressbooks'])) {
                if (!is_array($preset['extra_addressbooks'])) {
                    throw new \Exception("setting extra_addressbooks must be an array");
                }

                foreach (array_keys($preset['extra_addressbooks']) as $k) {
                    if (is_array($preset['extra_addressbooks'][$k])) {
                        /** @psalm-var PresetExtraAbook Checked by parsePresetArray() */
                        $xabook = $this->parsePresetArray(
                            self::PRESET_SETTINGS_EXTRA_ABOOK,
                            $preset['extra_addressbooks'][$k],
                            $result
                        );

                        $result['extra_addressbooks'][$xabook['url']] = $xabook;
                    } else {
                        throw new \Exception("setting extra_addressbooks[$k] must be an array");
                    }
                }
            }

            $this->presets[$presetName] = $result;
        } catch (\Exception $e) {
            $logger->error("Error in preset $presetName: " . $e->getMessage());
        }
    }

    /**
     * Parses / checks a user-input array according to a settings specification.
     *
     * @param array<string, SettingSpecification> $spec The specification of the expected fields.
     * @param array $preset The user-input array
     * @param null|Preset $defaults An optional array with defaults that take precedence over defaults in $spec.
     * @return array If no error, the resulting array, containing only attributes from $spec.
     */
    private function parsePresetArray(array $spec, array $preset, ?array $defaults = null): array
    {
        $result = [];
        foreach ($spec as $attr => $specs) {
            [ $type, $mandatory, $defaultValue ] = $specs;

            if (isset($preset[$attr])) {
                switch ($type) {
                    case 'skip':
                        // this item has a special handler
                        continue 2;

                    case 'string':
                    case 'timestr':
                        if (is_string($preset[$attr])) {
                            if ($type == 'timestr') {
                                $result[$attr] = Utils::parseTimeParameter($preset[$attr]);
                            } else {
                                $result[$attr] = $preset[$attr];
                            }
                        } else {
                            throw new \Exception("setting $attr must be a string");
                        }
                        break;

                    case 'bool':
                        if (is_bool($preset[$attr])) {
                            $result[$attr] = $preset[$attr];
                        } else {
                            throw new \Exception("setting $attr must be boolean");
                        }
                        break;

                    case 'string[]':
                        if (is_array($preset[$attr])) {
                            $result[$attr] = [];
                            foreach (array_keys($preset[$attr]) as $k) {
                                if (is_string($preset[$attr][$k])) {
                                    $result[$attr][] = $preset[$attr][$k];
                                } else {
                                    throw new \Exception("setting $attr\[$k\] must be string");
                                }
                            }
                        } else {
                            throw new \Exception("setting $attr must be array");
                        }
                }
            } elseif ($mandatory) {
                throw new \Exception("required setting $attr is not set");
            } else {
                $result[$attr] = $defaults[$attr] ?? $defaultValue;
            }
        }

        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120