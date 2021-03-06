<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Configuration holder. Singleton class accessed statically, encapsulates the confDriver implementation.
 * @package Pydio
 * @subpackage Core
 */
class ConfService
{
    private static $instance;
    public static $useSession = true;

    private $booter;
    private $confPlugin;
    private $cachePlugin;
    private $errors = array();
    private $configs = array();

    private $contextRepositoryId;
    private $contextCharset;

    /**
     * @param AJXP_PluginsService $ajxpPluginService
     * @return AbstractConfDriver
     */
    public function confPluginSoftLoad()
    {
        $this->booter = AJXP_PluginsService::getInstance()->softLoad("boot.conf", array());
        $coreConfigs = $this->booter->loadPluginConfig("core", "conf");
        $corePlug = AJXP_PluginsService::getInstance()->softLoad("core.conf", array());
        $corePlug->loadConfigs($coreConfigs);
        return $corePlug->getConfImpl();

    }

    /**
     * @param AJXP_PluginsService $ajxpPluginService
     * @return AbstractCacheDriver
     */
    public function cachePluginSoftLoad()
    {
        $coreConfigs = array();
        $corePlug = AJXP_PluginsService::getInstance()->softLoad("core.cache", array());
        CoreConfLoader::loadBootstrapConfForPlugin("core.cache", $coreConfigs);
        if (!empty($coreConfigs)) $corePlug->loadConfigs($coreConfigs);
        return $corePlug->getCacheImpl();
    }

    /**
     * @return AbstractConfDriver
     */
    public static function getBootConfStorageImpl()
    {
        $inst = AJXP_PluginsService::getInstance()->findPluginById("boot.conf");
        if (empty($inst)) {
            $inst = AJXP_PluginsService::getInstance()->softLoad("boot.conf", array());
        }
        return $inst;
    }

    /**
     * Initialize singleton
     * @static
     * @return void
     */
    public static function init($installPath=AJXP_INSTALL_PATH, $pluginDir="plugins")
    {
        $inst = self::getInstance();
        $inst->initInst($installPath.DIRECTORY_SEPARATOR.$pluginDir);
    }

    /**
     * Load the boostrap_* files and their configs
     * @return void
     */
    public function initInst($pluginDirPath)
    {
        // INIT AS GLOBAL
        $this->configs["AVAILABLE_LANG"] = self::listAvailableLanguages();
        if (isSet($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) == "on") {
            $this->configs["USE_HTTPS"] = true;
        }
        if (isSet($this->configs["USE_HTTPS"])) {
            AJXP_Utils::safeIniSet("session.cookie_secure", true);
        }
        $this->configs["JS_DEBUG"] = AJXP_CLIENT_DEBUG;
        $this->configs["SERVER_DEBUG"] = AJXP_SERVER_DEBUG;

        if (is_file(AJXP_CONF_PATH."/bootstrap_repositories.php")) {
            $REPOSITORIES = array();
            include(AJXP_CONF_PATH."/bootstrap_repositories.php");
            $this->configs["DEFAULT_REPOSITORIES"] = $REPOSITORIES;
        } else {
            $this->configs["DEFAULT_REPOSITORIES"] = array();
        }

        // Try to load instance from cache first
        $this->cachePlugin = $this->cachePluginSoftLoad();
        if (AJXP_PluginsService::getInstance()->loadPluginsRegistryFromCache($this->cachePlugin)) {
            return;
        }

        $this->booter = AJXP_PluginsService::getInstance()->softLoad("boot.conf", array());
        $this->confPlugin = $this->confPluginSoftLoad();

        // Loading the registry
        try {
            AJXP_PluginsService::getInstance()->loadPluginsRegistry($pluginDirPath, $this->confPlugin);
        } catch (Exception $e) {
            die("Severe error while loading plugins registry : ".$e->getMessage());
        }
    }

    /**
     * Start the singleton
     * @static
     * @return void
     */
    public static function start()
    {
        $inst = self::getInstance();
        $inst->startInst();
    }
    /**
     * Init CONF, AUTH drivers
     * Init Repositories
     * @return void
     */
    public function startInst()
    {
        AJXP_PluginsService::getInstance()->setPluginUniqueActiveForType("conf", self::getConfStorageImpl()->getName());
    }
    /**
     * Get errors generated by the boot sequence (init/start)
     * @static
     * @return array
     */
    public static function getErrors()
    {
        return self::getInstance()->errors;
    }

    public static function getContextCharset(){
        if(self::$useSession) {
            if(isSet($_SESSION["AJXP_CHARSET"])) return $_SESSION["AJXP_CHARSET"];
            else return null;
        }else {
            return self::getInstance()->contextCharset;
        }
    }

    public static function setContextCharset($value){
        if(self::$useSession){
            $_SESSION["AJXP_CHARSET"] = $value;
        }else{
            self::getInstance()->contextCharset = $value;
        }
    }

    public static function clearContextCharset(){
        if(self::$useSession && isSet($_SESSION["AJXP_CHARSET"])){
            unset($_SESSION["AJXP_CHARSET"]);
        }else{
            self::getInstance()->contextCharset = null;
        }
    }

    public function getContextRepositoryId(){
        return self::$useSession ? $_SESSION["REPO_ID"] : $this->contextRepositoryId;
    }

    public static function clearAllCaches(){
        AJXP_PluginsService::clearPluginsCache();
        self::clearMessagesCache();
        CacheService::deleteAll();
        if(function_exists('opcache_reset')){
            opcache_reset();
        }
    }

    /**
     * @static
     * @param $globalsArray
     * @param string $interfaceCheck
     * @return AJXP_Plugin|null
     */
    public static function instanciatePluginFromGlobalParams($globalsArray, $interfaceCheck = "")
    {
        $plugin = false;

        if (is_string($globalsArray)) {
            $globalsArray = array("instance_name" => $globalsArray);
        }

        if (isSet($globalsArray["instance_name"])) {
            $pName = $globalsArray["instance_name"];
            unset($globalsArray["instance_name"]);

            $plugin = AJXP_PluginsService::getInstance()->softLoad($pName, $globalsArray);
            $plugin->performChecks();
        }

        if ($plugin != false && !empty($interfaceCheck)) {
            if (!is_a($plugin, $interfaceCheck)) {
                $plugin = false;
            }
        }
        if ($plugin !== false) {
            AJXP_PluginsService::getInstance()->setPluginActive($plugin->getType(), $plugin->getName(), true, $plugin);
        }
        return $plugin;

    }

    /**
     * Check if the STDIN constant is defined
     * @static
     * @return bool
     */
    public static function currentContextIsCommandLine()
    {
        return php_sapi_name() === "cli";
    }

    protected static $restAPIContext;

    /**
     * Set or get if we are currently running REST
     * @static
     * @param string $restBase
     * @return bool
     */
    public static function currentContextIsRestAPI($restBase = '')
    {
        if(!empty($restBase)){
            self::$restAPIContext = $restBase;
            return $restBase;
        }else{
            return self::$restAPIContext;
        }
    }

    /**
     * Check the presence of mcrypt and option CMDLINE_ACTIVE
     * @static
     * @return bool
     */
    public static function backgroundActionsSupported()
    {
        return function_exists("mcrypt_create_iv") && ConfService::getCoreConf("CMDLINE_ACTIVE");
    }

    /**
     * @var AbstractConfDriver
     */
    private static $tmpConfStorageImpl;
    /**
     * @var AbstractAuthDriver
     */
    private static $tmpAuthStorageImpl;
    /**
     * @var AbstractCacheDriver
     */
    private static $tmpCacheStorageImpl;

    /**
     * @param $confStorage AbstractConfDriver
     * @param $authStorage AbstractAuthDriver
     * @param $cacheStorage AbstractCacheDriver
     */
    public static function setTmpStorageImplementations($confStorage, $authStorage, $cacheStorage)
    {
        self::$tmpConfStorageImpl = $confStorage;
        self::$tmpAuthStorageImpl = $authStorage;
        self::$tmpCacheStorageImpl = $cacheStorage;
    }

    /**
     * Get conf driver implementation
     *
     * @return AbstractConfDriver
     */
    public static function getConfStorageImpl()
    {
        if(isSet(self::$tmpConfStorageImpl)) return self::$tmpConfStorageImpl;
        return AJXP_PluginsService::getInstance()->getPluginById("core.conf")->getConfImpl();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractAuthDriver
     */
    public static function getAuthDriverImpl()
    {
        if(isSet(self::$tmpAuthStorageImpl)) return self::$tmpAuthStorageImpl;
        return AJXP_PluginsService::getInstance()->getPluginById("core.auth")->getAuthImpl();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractCacheDriver
     */
    public static function getCacheDriverImpl()
    {
        if(isSet(self::$tmpCacheStorageImpl)) return self::$tmpCacheStorageImpl;
        return AJXP_PluginsService::getInstance()->getPluginById("core.cache")->getCacheImpl();
    }

    public static function getFilteredXMLRegistry($extendedVersion = true, $clone = false, $useCache = false){

        if($useCache){
            $cacheKey = self::getRegistryCacheKey($extendedVersion);
            $cachedXml = CacheService::fetch($cacheKey);
            if($cachedXml !== false){
                $registry = new DOMDocument("1.0", "utf-8");
                $registry->loadXML($cachedXml);
                AJXP_PluginsService::updateXmlRegistry($registry, $extendedVersion);
                if($clone){
                    return $registry->cloneNode(true);
                }else{
                    return $registry;
                }
            }
        }

        $registry = AJXP_PluginsService::getXmlRegistry($extendedVersion);
        $changes = self::filterRegistryFromRole($registry);
        if($changes){
            AJXP_PluginsService::updateXmlRegistry($registry, $extendedVersion);
        }

        if($useCache && isSet($cacheKey)){
            CacheService::save($cacheKey, $registry->saveXML());
        }

        if($clone){
            $cloneDoc = $registry->cloneNode(true);
            $registry = $cloneDoc;
        }
        return $registry;

    }

    private static function getRegistryCacheKey($extendedVersion = true){

        $logged = AuthService::getLoggedUser();
        $u = $logged == null ? "shared" : $logged->getId();
        $a = "norepository";
        $r = ConfService::getRepository();
        if($r !== null){
            $a = $r->getSlug();
        }
        $v = $extendedVersion ? "extended":"light";
        return "xml_registry:".$v.":".$u.":".$a;

    }

    /**
     * Check the current user "specificActionsRights" and filter the full registry actions with these.
     * @static
     * @param DOMDocument $registry
     * @return bool
     */
    public static function filterRegistryFromRole(&$registry)
    {
        if(!AuthService::usersEnabled()) return false ;
        $loggedUser = AuthService::getLoggedUser();
        if($loggedUser == null) return false;
        $crtRepo = ConfService::getRepository();
        $crtRepoId = AJXP_REPO_SCOPE_ALL; // "ajxp.all";
        if ($crtRepo != null && is_a($crtRepo, "Repository")) {
            $crtRepoId = $crtRepo->getId();
        }
        $actionRights = $loggedUser->mergedRole->listActionsStatesFor($crtRepo);
        $changes = false;
        $xPath = new DOMXPath($registry);
        foreach ($actionRights as $pluginName => $actions) {
            foreach ($actions as $actionName => $enabled) {
                if($enabled !== false) continue;
                $actions = $xPath->query("actions/action[@name='$actionName']");
                if (!$actions->length) {
                    continue;
                }
                $action = $actions->item(0);
                $action->parentNode->removeChild($action);
                $changes = true;
            }
        }
        $parameters = $loggedUser->mergedRole->listParameters();
        foreach ($parameters as $scope => $paramsPlugs) {
            if ($scope === AJXP_REPO_SCOPE_ALL || $scope === $crtRepoId || ($crtRepo!=null && $crtRepo->hasParent() && $scope === AJXP_REPO_SCOPE_SHARED)) {
                foreach ($paramsPlugs as $plugId => $params) {
                    foreach ($params as $name => $value) {
                        // Search exposed plugin_configs, replace if necessary.
                        $searchparams = $xPath->query("plugins/*[@id='$plugId']/plugin_configs/property[@name='$name']");
                        if(!$searchparams->length) continue;
                        $param = $searchparams->item(0);
                        $newCdata = $registry->createCDATASection(json_encode($value));
                        $param->removeChild($param->firstChild);
                        $param->appendChild($newCdata);
                    }
                }
            }
        }
        return $changes;
    }


    /**
     * @param AbstractAjxpUser $loggedUser
     * @param String|int $parameterId
     * @return bool
     */
    public static function switchUserToActiveRepository($loggedUser, $parameterId = -1)
    {
        if (isSet($_SESSION["PENDING_REPOSITORY_ID"]) && isSet($_SESSION["PENDING_FOLDER"])) {
            $loggedUser->setArrayPref("history", "last_repository", $_SESSION["PENDING_REPOSITORY_ID"]);
            $loggedUser->setPref("pending_folder", $_SESSION["PENDING_FOLDER"]);
            AuthService::updateUser($loggedUser);
            unset($_SESSION["PENDING_REPOSITORY_ID"]);
            unset($_SESSION["PENDING_FOLDER"]);
        }
        $currentRepoId = ConfService::getCurrentRepositoryId();
        $lastRepoId  = $loggedUser->getArrayPref("history", "last_repository");
        $defaultRepoId = AuthService::getDefaultRootId();
        if ($defaultRepoId == -1) {
            return false;
        } else {
            if ($lastRepoId !== "" && $lastRepoId!==$currentRepoId && $parameterId == -1 && $loggedUser->canSwitchTo($lastRepoId)) {
                ConfService::switchRootDir($lastRepoId);
            } else if ($parameterId != -1 && $loggedUser->canSwitchTo($parameterId)) {
                ConfService::switchRootDir($parameterId);
            } else if (!$loggedUser->canSwitchTo($currentRepoId)) {
                ConfService::switchRootDir($defaultRepoId);
            }
        }
        return true;
    }


    /**
     * See instance method
     * @static
     * @param $rootDirIndex
     * @param bool $temporary
     * @return void
     */
    public static function switchRootDir($rootDirIndex = -1, $temporary = false)
    {
        self::getInstance()->switchRootDirInst($rootDirIndex, $temporary);
    }
    /**
     * Switch the current repository
     * @param $rootDirIndex
     * @param bool $temporary
     * @return void
     */
    public function switchRootDirInst($rootDirIndex=-1, $temporary=false)
    {
        if ($rootDirIndex == -1) {
            $ok = false;
            if (isSet($_SESSION['REPO_ID']) || $this->contextRepositoryId != null) {
                $sessionId = self::$useSession ? $_SESSION['REPO_ID']  : $this->contextRepositoryId;
                $object = self::getRepositoryById($sessionId);
                if($object != null && self::repositoryIsAccessible($sessionId, $object)){
                    $this->configs["REPOSITORY"] = $object;
                    $ok = true;
                }
            }
            if(!$ok) {
                $currentRepos = $this->getLoadedRepositories();
                $keys = array_keys($currentRepos);
                $this->configs["REPOSITORY"] = $currentRepos[$keys[0]];
                if(self::$useSession){
                    $_SESSION['REPO_ID'] = $keys[0];
                }else{
                    $this->contextRepositoryId = $keys[0];
                }
            }
        } else {
            $object = self::getRepositoryById($rootDirIndex);
            if($temporary && ($object == null || !self::repositoryIsAccessible($rootDirIndex, $object))) {
                throw new AJXP_Exception("Trying to switch to an unauthorized repository");
            }
            if ($temporary && (isSet($_SESSION['REPO_ID']) || $this->contextRepositoryId != null)) {
                $crtId =  self::$useSession ? $_SESSION['REPO_ID']  : $this->contextRepositoryId;
                if ($crtId != $rootDirIndex && !isSet($_SESSION['SWITCH_BACK_REPO_ID'])) {
                    $_SESSION['SWITCH_BACK_REPO_ID'] = $crtId;
                    //AJXP_Logger::debug("switching to $rootDirIndex, registering $crtId");
                }
            } else {
                $crtId =  self::$useSession ? $_SESSION['REPO_ID']  : $this->contextRepositoryId;
                $_SESSION['PREVIOUS_REPO_ID'] = $crtId;
                //AJXP_Logger::debug("switching back to $rootDirIndex");
            }
            if (isSet($this->configs["REPOSITORIES"]) && isSet($this->configs["REPOSITORIES"][$rootDirIndex])) {
                $this->configs["REPOSITORY"] = $this->configs["REPOSITORIES"][$rootDirIndex];
            } else {
                $this->configs["REPOSITORY"] = ConfService::getRepositoryById($rootDirIndex);
            }
            if(self::$useSession){
                $_SESSION['REPO_ID'] = $rootDirIndex;
            }else{
                $this->contextRepositoryId = $rootDirIndex;
            }
            if(isSet($this->configs["ACCESS_DRIVER"])) unset($this->configs["ACCESS_DRIVER"]);
        }

        if (isSet($this->configs["REPOSITORY"]) && $this->configs["REPOSITORY"]->getOption("CHARSET")!="") {
            self::setContextCharset($this->configs["REPOSITORY"]->getOption("CHARSET"));
        } else {
            self::clearContextCharset();
        }


        if ($rootDirIndex!=-1 && AuthService::usersEnabled() && AuthService::getLoggedUser()!=null) {
            $loggedUser = AuthService::getLoggedUser();
            $loggedUser->setArrayPref("history", "last_repository", $rootDirIndex);
        }

    }

    /**
     * See instance method
     * @static
     * @param String $scope "user" or "all"
     * @param bool $includeShared
     * @return Repository[]
     */
    public static function getRepositoriesList($scope = "user", $includeShared = true)
    {
        if ($scope == "user") {
            return self::getInstance()->getLoadedRepositories();
        } else {
            return self::getInstance()->initRepositoriesListInst("all", $includeShared);
        }
    }

    /**
     * @return Repository[]
     */
    private function getLoadedRepositories()
    {
        if (!isSet($this->configs["REPOSITORIES"]) && isSet($_SESSION["REPOSITORIES"]) && is_array($_SESSION["REPOSITORIES"])){
            $sessionNotCorrupted = array_reduce($_SESSION["REPOSITORIES"], function($carry, $item){ return $carry && is_a($item, "Repository"); }, true);
            if($sessionNotCorrupted){
                $this->configs["REPOSITORIES"] = $_SESSION["REPOSITORIES"];
                return $_SESSION["REPOSITORIES"];
            }else if(isSet($this->configs["REPOSITORIES"])){
                unset($this->configs["REPOSITORIES"]);
            }
        }
        if (isSet($this->configs["REPOSITORIES"])) {
            return $this->configs["REPOSITORIES"];
        }
        $this->configs["REPOSITORIES"] = $this->initRepositoriesListInst();
        $_SESSION["REPOSITORIES"] = $this->configs["REPOSITORIES"];
        return $this->configs["REPOSITORIES"];
    }

    public function invalidateLoadedRepositories()
    {
        if(isSet($_SESSION["REPOSITORIES"])) unset($_SESSION["REPOSITORIES"]);
        $this->configs["REPOSITORIES"] = null;
        CacheService::deleteAll();
    }

    private function cacheRepository($repoId, $repository){
        if(!is_array($this->configs["REPOSITORIES"])) return;
        $this->configs["REPOSITORIES"][$repoId] = $repository;
        $_SESSION["REPOSITORIES"] = $this->configs["REPOSITORIES"];
    }

    private function removeRepositoryFromCache($repositoryId){
        if(!is_array($this->configs["REPOSITORIES"]) || !isSet($this->configs["REPOSITORIES"][$repositoryId])) return;
        unset($this->configs["REPOSITORIES"][$repositoryId]);
        $_SESSION["REPOSITORIES"] = $this->configs["REPOSITORIES"];
    }

    /**
     * @static
     * @param AbstractAjxpUser $userObject
     * @param bool $details
     * @param bool $labelOnly
     * @param bool $includeShared
     * @return Repository[]
     */
    public static function getAccessibleRepositories($userObject=null, $details=false, $labelOnly = false, $includeShared = true)
    {
        $result = array();
        $allReps = ConfService::getRepositoriesList("user");
        foreach ($allReps as $repositoryId => $repositoryObject) {
            if (!ConfService::repositoryIsAccessible($repositoryId, $repositoryObject, $userObject, $details, $includeShared)) {
                continue;
            }

            if ($labelOnly) {
                $result[$repositoryId] = $repositoryObject->getDisplay();
            } else {
                $result[$repositoryId] = $repositoryObject;
            }

        }
        return $result;
    }

    /**
     * @param String $repositoryId
     * @param Repository $repositoryObject
     * @param AbstractAjxpUser $userObject
     * @param bool $details
     * @param bool $includeShared
     *
     * @return bool
     */
    public static function repositoryIsAccessible($repositoryId, $repositoryObject, $userObject = null, $details=false, $includeShared=true)
    {
        if($userObject == null) $userObject = AuthService::getLoggedUser();
        if ($userObject == null && AuthService::usersEnabled()) {
            return false;
        }
        if (!AuthService::canAssign($repositoryObject, $userObject)) {
            return false;
        }
        if ($repositoryObject->isTemplate) {
            return false;
        }
        if (($repositoryObject->getAccessType()=="ajxp_conf" || $repositoryObject->getAccessType()=="ajxp_admin") && $userObject != null) {
            if (AuthService::usersEnabled() && !$userObject->isAdmin()) {
                return false;
            }
        }
        if ($repositoryObject->getAccessType()=="ajxp_user" && $userObject != null) {
            return ($userObject->canRead($repositoryId) || $userObject->canWrite($repositoryId)) ;
        }
        if ($repositoryObject->getAccessType() == "ajxp_shared" && !AuthService::usersEnabled()) {
            return false;
        }
        if ($repositoryObject->getUniqueUser() && (!AuthService::usersEnabled() || $userObject == null  || $userObject->getId() == "shared" || $userObject->getId() != $repositoryObject->getUniqueUser() )) {
            return false;
        }
        if ( $userObject != null && !($userObject->canRead($repositoryId) || $userObject->canWrite($repositoryId)) && !$details) {
            return false;
        }
        if ($userObject == null || $userObject->canRead($repositoryId) || $userObject->canWrite($repositoryId) || $details) {
            // Do not display standard repositories even in details mode for "sub"users
            if ($userObject != null && $userObject->hasParent() && !($userObject->canRead($repositoryId) || $userObject->canWrite($repositoryId) )) {
                return false;
            }
            // Do not display shared repositories otherwise.
            if ($repositoryObject->hasOwner() && !$includeShared && ($userObject == null || $userObject->getParent() != $repositoryObject->getOwner())) {
                return false;
            }
            if ($userObject != null && $repositoryObject->hasOwner() && !$userObject->hasParent()) {
                // Display the repositories if allow_crossusers is ok
                if(ConfService::getCoreConf("ALLOW_CROSSUSERS_SHARING", "conf") === false
                || ConfService::getCoreConf("ALLOW_CROSSUSERS_SHARING", "conf") === 0) {
                    return false;
                }
                // But still do not display its own shared repositories!
                if ($repositoryObject->getOwner() == $userObject->getId()) {
                    return false;
                }
            }
            if ($repositoryObject->hasOwner() && $userObject != null &&  $details && !($userObject->canRead($repositoryId) || $userObject->canWrite($repositoryId) ) ) {
                return false;
            }
        }
        $res = null;
        $args = array($repositoryId, $repositoryObject, $userObject, &$res);
        AJXP_Controller::applyIncludeHook("repository.test_access", $args);
        if($res === false){
            return false;
        }
        return true;
    }

    /**
     * Return the full list of repositories, as id => objects
     * @return array
     */
    public function getRepositoriesListInst()
    {
        return $this->getLoadedRepositories();
    }

    /**
     * See instance method
     * @static
     * @return string
     */
    public static function getCurrentRepositoryId()
    {
        return self::getInstance()->getCurrentRepositoryIdInst();
    }
    /**
     * Get the current repository ID;
     * @return string
     */
    public function getCurrentRepositoryIdInst()
    {
        $ctxId = $this->getContextRepositoryId();
        if(!empty($ctxId) || $ctxId."" === "0"){
            $object = self::getRepositoryById($ctxId);
            if($object != null && self::repositoryIsAccessible($ctxId, $object)){
                return $ctxId;
            }
        }
        $currentRepos = $this->getLoadedRepositories();
        $keys = array_keys($currentRepos);
        return array_shift($keys);
    }
    /**
     * Get the current repo label
     * @static
     * @return string
     */
    public static function getCurrentRootDirDisplay()
    {
        return self::getInstance()->getCurrentRootDirDisplayInst();
    }
    /**
     * @return string
     */
    public function getCurrentRootDirDisplayInst()
    {
        $currentRepos = $this->getLoadedRepositories();
        $ctxId = $this->getContextRepositoryId();
        if (isSet($currentRepos[$ctxId])) {
            $repo = $currentRepos[$ctxId];
            return $repo->getDisplay();
        }
        return "";
    }

    /**
     * @param Repository[] $repoList
     * @param array $criteria
     * @return Repository[] array
     */
    public static function filterRepositoryListWithCriteria($repoList, $criteria){
        $repositories = array();
        $searchableKeys = array("uuid", "parent_uuid", "owner_user_id", "display", "accessType", "isTemplate", "slug", "groupPath");
        foreach ($repoList as $repoId => $repoObject) {
            $failOneCriteria = false;
            foreach($criteria as $key => $value){
                if(!in_array($key, $searchableKeys)) continue;
                $criteriumOk = false;
                $comp = null;
                if($key == "uuid") $comp = $repoObject->getUniqueId();
                else if($key == "parent_uuid") $comp = $repoObject->getParentId();
                else if($key == "owner_user_id") $comp = $repoObject->getUniqueUser();
                else if($key == "display") $comp = $repoObject->getDisplay();
                else if($key == "accessType") $comp = $repoObject->getAccessType();
                else if($key == "isTemplate") $comp = $repoObject->isTemplate;
                else if($key == "slug") $comp = $repoObject->getSlug();
                else if($key == "groupPath") $comp = $repoObject->getGroupPath();
                if(is_array($value) && in_array($comp, $value)){
                    //$repositories[$repoId] = $repoObject;
                    $criteriumOk = true;
                }else if($value == AJXP_FILTER_EMPTY && empty($comp)){
                    //$repositories[$repoId] = $repoObject;
                    $criteriumOk = true;
                }else if($value == AJXP_FILTER_NOT_EMPTY && !empty($comp)){
                    //$repositories[$repoId] = $repoObject;
                    $criteriumOk = true;
                }else if(is_string($value) && strpos($value, "regexp:")===0 && preg_match(str_replace("regexp:", "", $value), $comp)){
                    //$repositories[$repoId] = $repoObject;
                    $criteriumOk = true;
                }else if($value == $comp){
                    //$repositories[$repoId] = $repoObject;
                    $criteriumOk = true;
                }
                if(!$criteriumOk) {
                    $failOneCriteria = true;
                    break;
                }
            }
            if(!$failOneCriteria){
                $repositories[$repoId] = $repoObject;
            }
        }
        return $repositories;
    }

    /**
     * @param array $criteria
     * @param $count
     * @return Repository[]
     */
    public static function listRepositoriesWithCriteria($criteria, &$count){

        $statics = array();
        foreach (self::getInstance()->configs["DEFAULT_REPOSITORIES"] as $index=>$repository) {
            $repo = self::createRepositoryFromArray($index, $repository);
            $repo->setWriteable(false);
            $statics[$repo->getId()] = $repo;
        }
        $statics = self::filterRepositoryListWithCriteria($statics, $criteria);
        $dyna = self::getInstance()->getConfStorageImpl()->listRepositoriesWithCriteria($criteria, $count);
        $count += count($statics);
        return array_merge($statics, $dyna);

    }

    /**
     * @param $scope String "user", "all"
     * @param bool $includeShared
     * @return array
     */
    protected function initRepositoriesListInst($scope = "user", $includeShared = true)
    {
        // APPEND CONF FILE REPOSITORIES
        $loggedUser = AuthService::getLoggedUser();
        $objList = array();
        if($loggedUser != null){
            $l = $loggedUser->getLock();
            if( !empty($l)) return $objList;
        }
        foreach ($this->configs["DEFAULT_REPOSITORIES"] as $index=>$repository) {
            $repo = self::createRepositoryFromArray($index, $repository);
            if($scope == "user" && $loggedUser != null && !self::repositoryIsAccessible($index, $repo, $loggedUser)){
                continue;
            }
            $repo->setWriteable(false);
            $objList["".$repo->getId()] = $repo;
        }
        // LOAD FROM DRIVER
        $confDriver = self::getConfStorageImpl();
        if($scope == "user"){
            $acls = array();
            if(AuthService::getLoggedUser() != null){
                $acls = AuthService::getLoggedUser()->mergedRole->listAcls(true);
            }
            if(!count($acls)) {
                $drvList = array();
            }else{
                $criteria = array(
                    "uuid" => array_keys($acls)
                );
                $drvList = $confDriver->listRepositoriesWithCriteria($criteria);
            }
        }else{
            if($includeShared){
                $drvList = $confDriver->listRepositories();
            }else{
                $drvList = $confDriver->listRepositoriesWithCriteria(array(
                    "owner_user_id" => AJXP_FILTER_EMPTY
                ));
            }
        }
        if (is_array($drvList)) {
            /**
             * @var $drvList Repository[]
             */
            foreach ($drvList as $repoId=>$repoObject) {
                $driver = AJXP_PluginsService::getInstance()->getPluginByTypeName("access", $repoObject->getAccessType());
                if (!is_object($driver) || !$driver->isEnabled()) {
                    unset($drvList[$repoId]);
                } else {
                    $repoObject->setId($repoId);
                    $drvList[$repoId] = $repoObject;
                }
                if($repoObject->hasParent() && !ConfService::findRepositoryByIdOrAlias($repoObject->getParentId())){
                    AJXP_Logger::error(__CLASS__, __FUNCTION__, "Disabling repository ".$repoObject->getSlug()." as parent cannot be correctly loaded.");
                    unset($drvList[$repoId]);
                }
            }
            foreach($drvList as $key => $value){
                $objList[$key] = $value;
            }
        }
        $args = array(&$objList, $scope, $includeShared);
        AJXP_Controller::applyIncludeHook("repository.list", $args);
        return $objList;
    }
    /**
     * See instance method
     * @static
     * @param bool $register
     * @return array
     */
    public static function detectRepositoryStreams($register = false)
    {
        return self::getInstance()->detectRepositoryStreamsInst($register);
    }
    /**
     * Call the detectStreamWrapper method
     * @param bool $register
     * @return array
     */
    public function detectRepositoryStreamsInst($register = false)
    {
        $streams = array();
        $currentRepos = $this->getLoadedRepositories();
        foreach ($currentRepos as $repository) {
            $repository->detectStreamWrapper($register, $streams);
        }
        return $streams;
    }

    /**
     * Create a repository object from a config options array
     *
     * @param integer $index
     * @param Array $repository
     * @return Repository
     */
    public static function createRepositoryFromArray($index, $repository)
    {
        return self::getInstance()->createRepositoryFromArrayInst($index, $repository);
    }
    /**
     * See static method
     * @param string $index
     * @param array $repository
     * @return Repository
     */
    public function createRepositoryFromArrayInst($index, $repository)
    {
        $repo = new Repository($index, $repository["DISPLAY"], $repository["DRIVER"]);
        if (isSet($repository["DISPLAY_ID"])) {
            $repo->setDisplayStringId($repository["DISPLAY_ID"]);
        }
        if (isSet($repository["DESCRIPTION_ID"])) {
            $repo->setDescription($repository["DESCRIPTION_ID"]);
        }
        if (isSet($repository["AJXP_SLUG"])) {
            $repo->setSlug($repository["AJXP_SLUG"]);
        }
        if (isSet($repository["IS_TEMPLATE"]) && $repository["IS_TEMPLATE"]) {
            $repo->isTemplate = true;
            $repo->uuid = $index;
        }
        if (array_key_exists("DRIVER_OPTIONS", $repository) && is_array($repository["DRIVER_OPTIONS"])) {
            foreach ($repository["DRIVER_OPTIONS"] as $oName=>$oValue) {
                $repo->addOption($oName, $oValue);
            }
        }
        // BACKWARD COMPATIBILITY!
        if (array_key_exists("PATH", $repository)) {
            $repo->addOption("PATH", $repository["PATH"]);
            $repo->addOption("CREATE", intval($repository["CREATE"]));
            $repo->addOption("RECYCLE_BIN", $repository["RECYCLE_BIN"]);
        }
        return $repo;
    }

    /**
     * Add dynamically created repository
     *
     * @param Repository $oRepository
     * @return -1|null if error
     */
    public static function addRepository($oRepository)
    {
        return self::getInstance()->addRepositoryInst($oRepository);
    }
    /**
     * @param Repository $oRepository
     * @return -1|null on error
     */
    public function addRepositoryInst($oRepository)
    {
        AJXP_Controller::applyHook("workspace.before_create", array($oRepository));
        $confStorage = self::getConfStorageImpl();
        $res = $confStorage->saveRepository($oRepository);
        if ($res == -1) {
            return $res;
        }
        AJXP_Controller::applyHook("workspace.after_create", array($oRepository));
        AJXP_Logger::info(__CLASS__,"Create Repository", array("repo_name"=>$oRepository->getDisplay()));
        $this->invalidateLoadedRepositories();
        return null;
    }

    /**
     * @param $idOrAlias
     * @return null|Repository
     */
    public static function findRepositoryByIdOrAlias($idOrAlias)
    {
        $repository = ConfService::getRepositoryById($idOrAlias);
        if($repository != null ) return $repository;
        $repository = ConfService::getRepositoryByAlias($idOrAlias);
        if($repository != null) return $repository;
        return null;
    }

    /**
     * Get the reserved slugs used for config defined repositories
     * @return array
     */
    public static function reservedSlugsFromConfig(){
        $inst = self::getInstance();
        $slugs = array();
        if(isSet($inst->configs["DEFAULT_REPOSITORIES"])){
            foreach($inst->configs["DEFAULT_REPOSITORIES"] as $repo){
                if(isSet($repo["AJXP_SLUG"])){
                    $slugs[] = $repo["AJXP_SLUG"];
                }
            }
        }
        return $slugs;
    }

    /**
     * Retrieve a repository object
     *
     * @param String $repoId
     * @return Repository
     */
    public static function getRepositoryById($repoId)
    {
        return self::getInstance()->getRepositoryByIdInst($repoId);
    }
    /**
     * See static method
     * @param $repoId
     * @return Repository|null
     */
    public function getRepositoryByIdInst($repoId)
    {
        if (isSet($this->configs["REPOSITORIES"]) && isSet($this->configs["REPOSITORIES"][$repoId])) {
            return $this->configs["REPOSITORIES"][$repoId];
        }
        if (iSset($this->configs["REPOSITORY"]) && $this->configs["REPOSITORY"]->getId()."" == $repoId) {
            return $this->configs["REPOSITORY"];
        }
        $test = CacheService::fetch("repository:".$repoId);
        if($test !== false){
            return $test;
        }
        $test =  $this->getConfStorageImpl()->getRepositoryById($repoId);
        if($test != null) {
            CacheService::save("repository:".$repoId, $test);
            return $test;
        }
        // Finally try to search in default repositories
        if (isSet($this->configs["DEFAULT_REPOSITORIES"]) && isSet($this->configs["DEFAULT_REPOSITORIES"][$repoId])) {
            $repo = self::createRepositoryFromArray($repoId, $this->configs["DEFAULT_REPOSITORIES"][$repoId]);
            $repo->setWriteable(false);
            CacheService::save("repository:".$repoId, $repo);
            return $repo;
        }
        $hookedRepo = null;
        $args = array($repoId, &$hookedRepo);
        AJXP_Controller::applyIncludeHook("repository.search", $args);
        if($hookedRepo !== null){
            return $hookedRepo;
        }
        return null;
    }

    /**
     * Retrieve a repository object by its slug
     *
     * @param String $repoAlias
     * @return Repository
     */
    public static function getRepositoryByAlias($repoAlias)
    {
        $repo = self::getConfStorageImpl()->getRepositoryByAlias($repoAlias);
        if($repo !== null) return $repo;
        // check default repositories
        return self::getInstance()->getRepositoryByAliasInstDefaults($repoAlias);
    }
    /**
     * See static method
     * @param $repoAlias
     * @return Repository|null
     */
    public function getRepositoryByAliasInstDefaults($repoAlias)
    {
        $conf = $this->configs["DEFAULT_REPOSITORIES"];
        foreach ($conf as $repoId => $repoDef) {
            if ($repoDef["AJXP_SLUG"] == $repoAlias) {
                $repo = self::createRepositoryFromArray($repoId, $repoDef);
                $repo->setWriteable(false);
                return $repo;
            }
        }
        return null;
    }


    /**
     * Replace a repository by an update one.
     *
     * @param String $oldId
     * @param Repository $oRepositoryObject
     * @return mixed
     */
    public static function replaceRepository($oldId, $oRepositoryObject)
    {
        return self::getInstance()->replaceRepositoryInst($oldId, $oRepositoryObject);
    }
    /**
     * See static method
     * @param $oldId
     * @param $oRepositoryObject
     * @return int
     */
    public function replaceRepositoryInst($oldId, $oRepositoryObject)
    {
        AJXP_Controller::applyHook("workspace.before_update", array($oRepositoryObject));
        $confStorage = self::getConfStorageImpl();
        $res = $confStorage->saveRepository($oRepositoryObject, true);
        if ($res == -1) {
            return -1;
        }
        AJXP_Controller::applyHook("workspace.after_update", array($oRepositoryObject));
        AJXP_Logger::info(__CLASS__,"Edit Repository", array("repo_name"=>$oRepositoryObject->getDisplay()));
        $this->invalidateLoadedRepositories();
        return 0;
    }
    /**
     * Set a temp repository id but not in the session
     * @static
     * @param $repositoryObject
     * @return void
     */
    public static function tmpReplaceRepository($repositoryObject)
    {
        $inst = self::getInstance();
        if (isSet($inst->configs["REPOSITORIES"][$repositoryObject->getUniqueId()])) {
            $inst->configs["REPOSITORIES"][$repositoryObject->getUniqueId()] = $repositoryObject;
        }
    }
    /**
     * Remove a repository using the conf driver implementation
     * @static
     * @param $repoId
     * @return int
     */
    public static function deleteRepository($repoId)
    {
        return self::getInstance()->deleteRepositoryInst($repoId);
    }
    /**
     * See static method
     * @param $repoId
     * @return int
     */
    public function deleteRepositoryInst($repoId)
    {
        AJXP_Controller::applyHook("workspace.before_delete", array($repoId));
        $confStorage = self::getConfStorageImpl();
        $shares = $confStorage->listRepositoriesWithCriteria(array("parent_uuid" => $repoId));
        $toDelete = array();
        foreach($shares as $share){
            $toDelete[] = $share->getId();
        }
        $res = $confStorage->deleteRepository($repoId);
        if ($res == -1) {
            return $res;
        }
        foreach($toDelete as $deleteId){
            $this->deleteRepositoryInst($deleteId);
        }
        AJXP_Controller::applyHook("workspace.after_delete", array($repoId));
        AJXP_Logger::info(__CLASS__,"Delete Repository", array("repo_id"=>$repoId));
        $this->invalidateLoadedRepositories();
        return 0;
    }

    /**
     * Check if the gzopen function exists
     * @static
     * @return bool
     */
    public static function zipEnabled()
    {
        return (function_exists("gzopen") || function_exists("gzopen64"));
    }

    /**
     * Check if users are allowed to browse ZIP content
     * @static
     * @return bool
     */
    public static function zipBrowsingEnabled()
    {
        if(!self::zipEnabled()) return false;
        return !ConfService::getCoreConf("DISABLE_ZIP_BROWSING");
    }

    /**
     * Check if users are allowed to create ZIP archive
     * @static
     * @return bool
     */
    public static function zipCreationEnabled()
    {
        if(!self::zipEnabled()) return false;
        return ConfService::getCoreConf("ZIP_CREATION");
    }


    /**
     * Get the list of all "conf" messages
     * @static
     * @param bool $forceRefresh Refresh the list
     * @return
     */
    public static function getMessagesConf($forceRefresh = false)
    {
        return self::getInstance()->getMessagesInstConf($forceRefresh);
    }
    /**
     * See static method
     * @param bool $forceRefresh
     * @return
     */
    public function getMessagesInstConf($forceRefresh = false)
    {
        // make sure they are loaded
        $this->getMessagesInst($forceRefresh);
        return $this->configs["CONF_MESSAGES"];
    }

    /**
     * Get all i18n message
     * @static
     * @param bool $forceRefresh
     * @return
     */
    public static function getMessages($forceRefresh = false)
    {
        return self::getInstance()->getMessagesInst($forceRefresh);
    }
    /**
     * Get i18n messages
     * @param bool $forceRefresh
     * @return
     */
    public function getMessagesInst($forceRefresh = false)
    {
        $crtLang = self::getLanguage();
        $messageCacheDir = dirname(AJXP_PLUGINS_MESSAGES_FILE)."/i18n";
        $messageFile = $messageCacheDir."/".$crtLang."_".basename(AJXP_PLUGINS_MESSAGES_FILE);
        if (isSet($this->configs["MESSAGES"]) && !$forceRefresh) {
            return $this->configs["MESSAGES"];
        }
        if (!isset($this->configs["MESSAGES"]) && is_file($messageFile)) {
            include($messageFile);
            if (isSet($MESSAGES)) {
                $this->configs["MESSAGES"] = $MESSAGES;
            }
            if (isSet($CONF_MESSAGES)) {
                $this->configs["CONF_MESSAGES"] = $CONF_MESSAGES;
            }
        } else {
            $this->configs["MESSAGES"] = array();
            $this->configs["CONF_MESSAGES"] = array();
            $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//i18n", "nodes");
            foreach ($nodes as $node) {
                $nameSpace = $node->getAttribute("namespace");
                $path = AJXP_INSTALL_PATH."/".$node->getAttribute("path");
                $lang = $crtLang;
                if (!is_file($path."/".$crtLang.".php")) {
                    $lang = "en"; // Default language, minimum required.
                }
                if (is_file($path."/".$lang.".php")) {
                    require($path."/".$lang.".php");
                    if (isSet($mess)) {
                        foreach ($mess as $key => $message) {
                            $this->configs["MESSAGES"][(empty($nameSpace)?"":$nameSpace.".").$key] = $message;
                        }
                    }
                }
                $lang = $crtLang;
                if (!is_file($path."/conf/".$crtLang.".php")) {
                    $lang = "en";
                }
                if (is_file($path."/conf/".$lang.".php")) {
                    $mess = array();
                    require($path."/conf/".$lang.".php");
                    $this->configs["CONF_MESSAGES"] = array_merge($this->configs["CONF_MESSAGES"], $mess);
                }
            }
            if(!is_dir($messageCacheDir)) mkdir($messageCacheDir);
            AJXP_VarsFilter::filterI18nStrings($this->configs["MESSAGES"]);
            AJXP_VarsFilter::filterI18nStrings($this->configs["CONF_MESSAGES"]);
            @file_put_contents($messageFile, "<?php \$MESSAGES = ".var_export($this->configs["MESSAGES"], true) ." ; \$CONF_MESSAGES = ".var_export($this->configs["CONF_MESSAGES"], true) ." ; ");
        }

        return $this->configs["MESSAGES"];
    }

    /**
     * Clear the messages cache
     */
    public static function clearMessagesCache(){
        $i18nFiles = glob(dirname(AJXP_PLUGINS_MESSAGES_FILE)."/i18n/*.ser");
        if (is_array($i18nFiles)) {
            foreach ($i18nFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Get all registered extensions, from both the conf/extensions.conf.php and from the plugins
     * @static
     * @return
     */
    public static function getRegisteredExtensions()
    {
        return self::getInstance()->getRegisteredExtensionsInst();
    }
    /**
     * See static method
     * @return
     */
    public function getRegisteredExtensionsInst()
    {
        if (!isSet($this->configs["EXTENSIONS"])) {
            $EXTENSIONS = array();
            $RESERVED_EXTENSIONS = array();
            include_once(AJXP_CONF_PATH."/extensions.conf.php");
            $EXTENSIONS = array_merge($RESERVED_EXTENSIONS, $EXTENSIONS);
            foreach ($EXTENSIONS as $key => $value) {
                unset($EXTENSIONS[$key]);
                $EXTENSIONS[$value[0]] = $value;
            }
            $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//extensions/extension", "nodes", true);
            $res = array();
            foreach ($nodes as $node) {
                $res[$node->getAttribute("mime")] = array($node->getAttribute("mime"), $node->getAttribute("icon"), $node->getAttribute("messageId"));
            }
            if (count($res)) {
                $EXTENSIONS = array_merge($EXTENSIONS, $res);
            }
            $this->configs["EXTENSIONS"] = $EXTENSIONS;
        }
        return $this->configs["EXTENSIONS"];
    }
    /**
     * Get the actions that declare to skip the secure token in the plugins
     * @static
     * @return array
     */
    public static function getDeclaredUnsecureActions()
    {
        $test = AJXP_PluginsService::getInstance()->loadFromPluginQueriesCache("//action[@skipSecureToken]");
        if (!empty($test) && is_array($test)) {
            return $test;
        } else {
            $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//action[@skipSecureToken]", "nodes", false, false, true);
            $res = array();
            foreach ($nodes as $node) {
                $res[] = $node->getAttribute("name");
            }
            AJXP_PluginsService::getInstance()->storeToPluginQueriesCache("//action[@skipSecureToken]", $res);
            return $res;
        }

    }
    /**
     * Detect available languages from the core i18n library
     * @static
     * @return array
     */
    public static function listAvailableLanguages()
    {
        // Cache in session!
        if (isSet($_SESSION["AJXP_LANGUAGES"]) && !isSet($_GET["refresh_langs"])) {
            return $_SESSION["AJXP_LANGUAGES"];
        }
        $langDir = AJXP_COREI18N_FOLDER;
        $languages = array();
        if (($dh = opendir($langDir))!==FALSE) {
            while (($file = readdir($dh)) !== false) {
                $matches = array();
                if (preg_match("/(.*)\.php/", $file, $matches) == 1) {
                    $fRadical = $matches[1];
                    include($langDir."/".$fRadical.".php");
                    $langName = isSet($mess["languageLabel"])?$mess["languageLabel"]:"Not Found";
                    $languages[$fRadical] = $langName;
                }
            }
            closedir($dh);
        }
        if (count($languages)) {
            $_SESSION["AJXP_LANGUAGES"] = $languages;
        }
        return $languages;
    }

    /**
     * Get a config by its name
     * @static
     * @param string $varName
     * @return mixed
     */
    public static function getConf($varName)
    {
        return self::getInstance()->getConfInst($varName);
    }
    /**
     * Set a config by its name
     * @static
     * @param string $varName
     * @param mixed $varValue
     * @return void
     */
    public static function setConf($varName, $varValue)
    {
        self::getInstance()->setConfInst($varName, $varValue);
    }
    /**
     * See static method
     * @param $varName
     * @return mixed
     */
    public function getConfInst($varName)
    {
        if (isSet($this->configs[$varName])) {
            return $this->configs[$varName];
        }
        if (defined("AJXP_".$varName)) {
            return constant("AJXP_".$varName);
        }
        return null;
    }
    /**
     * See static method
     * @param $varName
     * @param $varValue
     * @return void
     */
    public function setConfInst($varName, $varValue)
    {
        $this->configs[$varName] = $varValue;
    }
    /**
     * Get config from the core.$coreType plugin
     * @static
     * @param string $varName
     * @param string $coreType
     * @return mixed|null|string
     */
    public static function getCoreConf($varName, $coreType = "ajaxplorer")
    {
        $coreP = AJXP_PluginsService::getInstance()->findPlugin("core", $coreType);
        if($coreP === false) return null;
        $confs = $coreP->getConfigs();
        $confs = AuthService::filterPluginParameters("core.".$coreType, $confs);
        return (isSet($confs[$varName]) ? AJXP_VarsFilter::filter($confs[$varName]) : null);
    }

    /**
     * @var array Keep loaded labels in memory
     */
    private static $usersParametersCache = array();

    /**
     * @param string $parameterName Plugin parameter name
     * @param AbstractAjxpUser|string $userIdOrObject
     * @param string $pluginId Plugin name, core.conf by default
     * @param null $defaultValue
     * @return mixed
     */
    public static function getUserPersonalParameter($parameterName, $userIdOrObject, $pluginId="core.conf", $defaultValue=null){

        $cacheId = $pluginId."-".$parameterName;
        if(!isSet(self::$usersParametersCache[$cacheId])){
            self::$usersParametersCache[$cacheId] = array();
        }
        // Passed an already loaded object
        if(is_a($userIdOrObject, "AbstractAjxpUser")){
            $value = $userIdOrObject->personalRole->filterParameterValue($pluginId, $parameterName, AJXP_REPO_SCOPE_ALL, $defaultValue);
            self::$usersParametersCache[$cacheId][$userIdOrObject->getId()] = $value;
            if(empty($value) && !empty($defaultValue)) $value = $defaultValue;
            return $value;
        }
        // Already in memory cache
        if(isSet(self::$usersParametersCache[$cacheId][$userIdOrObject])){
            return self::$usersParametersCache[$cacheId][$userIdOrObject];
        }

        // Try to load personal role if it was already loaded.
        $uRole = AuthService::getRole("AJXP_USR_/".$userIdOrObject);
        if($uRole === false){
            $uObject = self::getConfStorageImpl()->createUserObject($userIdOrObject);
            if(isSet($uObject)){
                $uRole = $uObject->personalRole;
            }
        }
        if(empty($uRole)){
            return $defaultValue;
        }
        $value = $uRole->filterParameterValue($pluginId, $parameterName, AJXP_REPO_SCOPE_ALL, $defaultValue);
        if(empty($value) && !empty($defaultValue)) {
            $value = $userIdOrObject;
        }
        self::$usersParametersCache[$cacheId][$userIdOrObject] = $value;
        return $value;

    }

    /**
     * Set the language in the session
     * @static
     * @param string $lang
     * @return void
     */
    public static function setLanguage($lang)
    {
        self::getInstance()->setLanguageInst($lang);
    }
    /**
     * See static method
     * @param string $lang
     * @return void
     */
    public function setLanguageInst($lang)
    {
        if (array_key_exists($lang, $this->configs["AVAILABLE_LANG"])) {
            $this->configs["LANGUE"] = $lang;
        }
    }
    /**
     * Get the language from the session
     * @static
     * @return string
     */
    public static function getLanguage()
    {
        $lang = self::getInstance()->getConfInst("LANGUE");
        if ($lang == null) {
            $lang = self::getInstance()->getCoreConf("DEFAULT_LANGUAGE");
        }
        if(empty($lang)) return "en";
        return $lang;
    }

    /**
     * The current repository
     * @return Repository
     */
    public static function getRepository()
    {
        return self::getInstance()->getRepositoryInst();
    }
    /**
     * See static method
     * @return Repository
     */
    public function getRepositoryInst()
    {
        $ctxId = $this->getContextRepositoryId();
        if (!empty($ctxId) && isSet($this->configs["REPOSITORIES"])  &&  isSet($this->configs["REPOSITORIES"][$ctxId])) {
            return $this->configs["REPOSITORIES"][$ctxId];
        }
        return isSet($this->configs["REPOSITORY"])?$this->configs["REPOSITORY"]:null;
    }

    /**
     * Returns the repository access driver
     * @return AJXP_Plugin
     */
    public static function loadRepositoryDriver()
    {
        return self::getInstance()->loadRepositoryDriverInst();
    }

    /**
     * @static
     * @param Repository $repository
     * @return AbstractAccessDriver
     */
    public static function loadDriverForRepository(&$repository)
    {
        return self::getInstance()->loadRepositoryDriverInst($repository);
    }

    /**
     * See static method
     * @param Repository|null $repository
     * @throws AJXP_Exception|Exception
     * @return AbstractAccessDriver
     */
    private function loadRepositoryDriverInst(&$repository = null)
    {
        $rest = false;
        if($repository == null){
            if (isSet($this->configs["ACCESS_DRIVER"]) && is_a($this->configs["ACCESS_DRIVER"], "AbstractAccessDriver")) {
                return $this->configs["ACCESS_DRIVER"];
            }
            $this->switchRootDirInst();
            $repository = $this->getRepositoryInst();
            if($repository == null){
                throw new Exception("No active repository found for user!");
            }
        }else{
            $rest = true;
            if (isset($repository->driverInstance)) {
                return $repository->driverInstance;
            }
        }
        /**
         * @var AbstractAccessDriver $plugInstance
         */
        $accessType = $repository->getAccessType();
        $pServ = AJXP_PluginsService::getInstance();
        $plugInstance = $pServ->getPluginByTypeName("access", $accessType);

        // TRIGGER BEFORE INIT META
        $metaSources = $repository->getOption("META_SOURCES");
        if (isSet($metaSources) && is_array($metaSources) && count($metaSources)) {
            $keys = array_keys($metaSources);
            foreach ($keys as $plugId) {
                if($plugId == "") continue;
                $instance = $pServ->getPluginById($plugId);
                if (!is_object($instance)) {
                    continue;
                }
                if (!method_exists($instance, "beforeInitMeta")) {
                    continue;
                }
                try {
                    $instance->init(AuthService::filterPluginParameters($plugId, $metaSources[$plugId], $repository->getId()));
                    $instance->beforeInitMeta($plugInstance, $repository);
                } catch (Exception $e) {
                    AJXP_Logger::error(__CLASS__, 'Meta plugin', 'Cannot instanciate Meta plugin, reason : '.$e->getMessage());
                    $this->errors[] = $e->getMessage();
                }
            }
        }

        // INIT MAIN DRIVER
        $plugInstance->init($repository);
        try {
            $plugInstance->initRepository();
            $repository->driverInstance = $plugInstance;
        } catch (Exception $e) {
            if(!$rest){
                // Remove repositories from the lists
                if(!is_a($e, "AJXP_UserAlertException")){
                    $this->removeRepositoryFromCache($repository->getId());
                }
                if (isSet($_SESSION["PREVIOUS_REPO_ID"]) && $_SESSION["PREVIOUS_REPO_ID"] !=$repository->getId()) {
                    $this->switchRootDir($_SESSION["PREVIOUS_REPO_ID"]);
                } else {
                    $this->switchRootDir();
                }
            }
            throw $e;
        }

        AJXP_PluginsService::deferBuildingRegistry();
        $pServ->setPluginUniqueActiveForType("access", $accessType);

        // TRIGGER INIT META
        $metaSources = $repository->getOption("META_SOURCES");
        if (isSet($metaSources) && is_array($metaSources) && count($metaSources)) {
            $keys = array_keys($metaSources);
            foreach ($keys as $plugId) {
                if($plugId == "") continue;
                $split = explode(".", $plugId);
                $instance = $pServ->getPluginById($plugId);
                if (!is_object($instance)) {
                    continue;
                }
                try {
                    $instance->init(AuthService::filterPluginParameters($plugId, $metaSources[$plugId], $repository->getId()));
                    if(!method_exists($instance, "initMeta")) {
                        throw new Exception("Meta Source $plugId does not implement the initMeta method.");
                    }
                    $instance->initMeta($plugInstance);
                } catch (Exception $e) {
                    AJXP_Logger::error(__CLASS__, 'Meta plugin', 'Cannot instanciate Meta plugin, reason : '.$e->getMessage());
                    $this->errors[] = $e->getMessage();
                }
                $pServ->setPluginActive($split[0], $split[1]);
            }
        }
        AJXP_PluginsService::flushDeferredRegistryBuilding();
        if (count($this->errors)>0) {
            $e = new AJXP_Exception("Error while loading repository feature : ".implode(",",$this->errors));
            if(!$rest){
                // Remove repositories from the lists
                $this->removeRepositoryFromCache($repository->getId());
                if (isSet($_SESSION["PREVIOUS_REPO_ID"]) && $_SESSION["PREVIOUS_REPO_ID"] !=$repository->getId()) {
                    $this->switchRootDir($_SESSION["PREVIOUS_REPO_ID"]);
                } else {
                    $this->switchRootDir();
                }
            }
            throw $e;
        }
        if($rest){
            $ctxId = $this->getContextRepositoryId();
            if ( (!empty($ctxId) || $ctxId === 0) && $ctxId == $repository->getId()) {
                $this->configs["REPOSITORY"] = $repository;
                $this->cacheRepository($ctxId, $repository);
            }
        } else {
            $this->configs["ACCESS_DRIVER"] = $plugInstance;
        }
        return $plugInstance;
    }

    /**
     * Search the manifests declaring ajxpdriver as their root node. Remove ajxp_* drivers
     * @static
     * @param string $filterByTagName
     * @param string $filterByDriverName
     * @param bool $limitToEnabledPlugins
     * @return string
     */
    public static function availableDriversToXML($filterByTagName = "", $filterByDriverName="", $limitToEnabledPlugins = false)
    {
        $nodeList = AJXP_PluginsService::searchAllManifests("//ajxpdriver", "node", false, $limitToEnabledPlugins);
        $xmlBuffer = "";
        foreach ($nodeList as $node) {
            $dName = $node->getAttribute("name");
            if($filterByDriverName != "" && $dName != $filterByDriverName) continue;
            if(strpos($dName, "ajxp_") === 0) continue;
            if ($filterByTagName == "") {
                $xmlBuffer .= $node->ownerDocument->saveXML($node);
                continue;
            }
            $q = new DOMXPath($node->ownerDocument);
            $cNodes = $q->query("//".$filterByTagName, $node);
            $xmlBuffer .= "<ajxpdriver ";
            foreach($node->attributes as $attr) $xmlBuffer.= " $attr->name=\"$attr->value\" ";
            $xmlBuffer .=">";
            foreach ($cNodes as $child) {
                $xmlBuffer .= $child->ownerDocument->saveXML($child);
            }
            $xmlBuffer .= "</ajxpdriver>";
        }
        return $xmlBuffer;
    }

     /**
      * Singleton method
      *
      * @return ConfService the service instance
      */
     public static function getInstance()
     {
         if (!isSet(self::$instance)) {
             $c = __CLASS__;
             self::$instance = new $c;
         }
         return self::$instance;
     }
     private function __construct(){}
    public function __clone()
    {
        trigger_error("Cannot clone me, i'm a singleton!", E_USER_ERROR);
    }

}
