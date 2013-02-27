<?php
/*
 * Copyright 2007-2011 Charles du Jeu <contact (at) cdujeu.me>
 * This file is part of AjaXplorer.
 *
 * AjaXplorer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AjaXplorer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with AjaXplorer.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://www.ajaxplorer.info/>.
 *
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * @package info.ajaxplorer.plugins
 * @class ajxp_confAccessDriver
 * AJXP_Plugin to access the configurations data
 */
class ajxp_confAccessDriver extends AbstractAccessDriver 
{	

    private $listSpecialRoles = AJXP_SERVER_DEBUG;

	function listAllActions($action, $httpVars, $fileVars){
        if(!isSet($this->actions[$action])) return;
        parent::accessPreprocess($action, $httpVars, $fileVars);
        $loggedUser = AuthService::getLoggedUser();
        if(AuthService::usersEnabled() && !$loggedUser->isAdmin()) return ;
        $mess = ConfService::getMessages();
        switch($action)
        {
            //------------------------------------
            //	BASIC LISTING
            //------------------------------------
            case "list_all_plugins_actions":
                $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//action", "node", false, true, true);
                $actions = array();
                $mess = ConfService::getMessages();
                foreach($nodes as $node){
                    $xPath = new DOMXPath($node->ownerDocument);
                    $proc = $xPath->query("processing", $node);
                    if(!$proc->length) continue;
                    $txt = $xPath->query("gui/@text", $node);
                    if($txt->length){
                        $messId = $txt->item(0)->nodeValue;
                    }else{
                        $messId = "";
                    }
                    $parentPlugin = $node->parentNode->parentNode->parentNode;
                    $pId = $parentPlugin->attributes->getNamedItem("id")->nodeValue;
                    if(empty($pId)){
                        $pId = $parentPlugin->nodeName .".";
                        if($pId == "ajxpdriver.") $pId = "access.";
                        $pId .= $parentPlugin->attributes->getNamedItem("name")->nodeValue;
                    }
                    //echo($pId." : ". $node->attributes->getNamedItem("name")->nodeValue . " (".$messId.")<br>");
                    if(!is_array($actions[$pId])) $actions[$pId] = array();
                    $actionName = $node->attributes->getNamedItem("name")->nodeValue;
                    $actions[$pId][$actionName] = array( "action" => $actionName , "label" => $messId);

                }
                foreach($actions as $actPid => $actionGroup){
                    ksort($actionGroup, SORT_STRING);
                    $actions[$actPid] = array();
                    foreach($actionGroup as $k => $v){
                        $actions[$actPid][] = $v;
                    }
                }
                HTMLWriter::charsetHeader("application/json");
                echo json_encode(array("LIST" => $actions, "HAS_GROUPS" => true));
                break;
            case "list_all_plugins_parameters":
                $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//param|//global_param", "node", false, true, true);
                $actions = array();
                $mess = ConfService::getMessages();
                foreach($nodes as $node){
                    if($node->parentNode->nodeName != "server_settings") continue;
                    $parentPlugin = $node->parentNode->parentNode;
                    $pId = $parentPlugin->attributes->getNamedItem("id")->nodeValue;
                    if(empty($pId)){
                        $pId = $parentPlugin->nodeName .".";
                        if($pId == "ajxpdriver.") $pId = "access.";
                        $pId .= $parentPlugin->attributes->getNamedItem("name")->nodeValue;
                    }
                    //echo($pId." : ". $node->attributes->getNamedItem("name")->nodeValue . " (".$messId.")<br>");
                    if(!is_array($actions[$pId])) $actions[$pId] = array();
                    $actionName = $node->attributes->getNamedItem("name")->nodeValue;
                    $messId = $node->attributes->getNamedItem("label")->nodeValue;
                    $actions[$pId][$actionName] = array( "parameter" => $actionName , "label" => AJXP_XMLWriter::replaceAjxpXmlKeywords($messId));

                }
                foreach($actions as $actPid => $actionGroup){
                    ksort($actionGroup, SORT_STRING);
                    $actions[$actPid] = array();
                    foreach($actionGroup as $k => $v){
                        $actions[$actPid][] = $v;
                    }
                }
                HTMLWriter::charsetHeader("application/json");
                echo json_encode(array("LIST" => $actions, "HAS_GROUPS" => true));
                break;
            case "parameters_to_form_definitions" :

                $data = json_decode(AJXP_Utils::decodeSecureMagic($httpVars["json_parameters"]), true);
                AJXP_XMLWriter::header("standard_form");
                foreach($data as $repoScope => $pluginsData){
                    echo("<repoScope id='$repoScope'>");
                    foreach($pluginsData as $pluginId => $paramData){
                        foreach($paramData as $paramId => $paramValue){
                            $query = "//param[@name='$paramId']|//global_param[@name='$paramId']";
                            $nodes = AJXP_PluginsService::getInstance()->searchAllManifests($query, "node", false, true, true);
                            if(!count($nodes)) continue;
                            $n = $nodes[0];
                            if($n->attributes->getNamedItem("group") != null){
                                $n->attributes->getNamedItem("group")->nodeValue = "$pluginId";
                            }else{
                                $n->appendChild($n->ownerDocument->createAttribute("group"));
                                $n->attributes->getNamedItem("group")->nodeValue = "$pluginId";
                            }
                            if(is_bool($paramValue)) $paramValue = ($paramValue ? "true" : "false");
                            if($n->attributes->getNamedItem("default") != null){
                                $n->attributes->getNamedItem("default")->nodeValue = $paramValue;
                            }else{
                                $n->appendChild($n->ownerDocument->createAttribute("default"));
                                $n->attributes->getNamedItem("default")->nodeValue = $paramValue;
                            }
                            echo(AJXP_XMLWriter::replaceAjxpXmlKeywords($n->ownerDocument->saveXML($n)));
                        }
                    }
                    echo("</repoScope>");
                }
                AJXP_XMLWriter::close("standard_form");
                break;

            default:
                break;
        }
    }

    function parseSpecificContributions(&$contribNode){
        parent::parseSpecificContributions($contribNode);
        if($contribNode->nodeName != "actions") return;
        $currentUserIsGroupAdmin = (AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/");
        if(!$currentUserIsGroupAdmin) return;
        $actionXpath=new DOMXPath($contribNode->ownerDocument);
        $publicUrlNodeList = $actionXpath->query('action[@name="create_repository"]/subMenu', $contribNode);
        if($publicUrlNodeList->length){
            $publicUrlNode = $publicUrlNodeList->item(0);
            $publicUrlNode->parentNode->removeChild($publicUrlNode);
        }
    }

	function switchAction($action, $httpVars, $fileVars){
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);
		$loggedUser = AuthService::getLoggedUser();
		if(AuthService::usersEnabled() && !$loggedUser->isAdmin()) return ;
		
		if($action == "edit"){
			if(isSet($httpVars["sub_action"])){
				$action = $httpVars["sub_action"];
			}
		}
		$mess = ConfService::getMessages();
        $currentUserIsGroupAdmin = (AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/");
		
		switch($action)
		{			
			//------------------------------------
			//	BASIC LISTING
			//------------------------------------
			case "ls":

                $rootNodes = array(
                    "data" => array(
                        "LABEL" => $mess["ajxp_conf.110"],
                        "ICON" => "user.png",
                        "CHILDREN" => array(
                            "repositories" => array("LABEL" => $mess["ajxp_conf.3"], "ICON" => "hdd_external_unmount.png", "LIST" => "listRepositories"),
                            "users" => array("LABEL" => $mess["ajxp_conf.2"], "ICON" => "users-folder.png", "LIST" => "listUsers"),
                            "roles" => array("LABEL" => $mess["ajxp_conf.69"], "ICON" => "user-acl.png", "LIST" => "listRoles"),
                        )
                    ),
                    "config" => array(
                        "LABEL" => $mess["ajxp_conf.109"],
                        "ICON" => "preferences_desktop.png",
                        "CHILDREN" => array(
                            "core"	   	   => array("LABEL" => $mess["ajxp_conf.98"], "ICON" => "preferences_desktop.png", "LIST" => "listPlugins"),
                            "plugins"	   => array("LABEL" => $mess["ajxp_conf.99"], "ICON" => "folder_development.png", "LIST" => "listPlugins"),
                        )
                    ),
                    "admin" => array(
                        "LABEL" => $mess["ajxp_conf.111"],
                        "ICON" => "toggle_log.png",
                        "CHILDREN" => array(
                            "logs" => array("LABEL" => $mess["ajxp_conf.4"], "ICON" => "toggle_log.png", "LIST" => "listLogFiles"),
                            "files" => array("LABEL" => $mess["ajxp_shared.3"], "ICON" => "html.png", "LIST" => "listSharedFiles"),
                            "diagnostic" => array("LABEL" => $mess["ajxp_conf.5"], "ICON" => "susehelpcenter.png", "LIST" => "printDiagnostic")
                        )
                    ),
                );
                if($currentUserIsGroupAdmin){
                    unset($rootNodes["config"]);
                    unset($rootNodes["admin"]);
                }
                AJXP_Controller::applyHook("ajxp_conf.list_config_nodes", array(&$rootNodes));
				$dir = trim(AJXP_Utils::decodeSecureMagic((isset($httpVars["dir"])?$httpVars["dir"]:"")), " /");
                if($dir != ""){
                    $hash = null;
                    if(strstr(urldecode($dir), "#") !== false){
                        list($dir, $hash) = explode("#", urldecode($dir));
                    }
    				$splits = explode("/", $dir);
                    $root = array_shift($splits);
                    if(count($splits)){
                        $child = $splits[0];
                        if(isSet($rootNodes[$root]["CHILDREN"][$child])){
                            $callback = $rootNodes[$root]["CHILDREN"][$child]["LIST"];
                            if(is_string($callback) && method_exists($this, $callback)){
                                AJXP_XMLWriter::header();
                                call_user_func(array($this, $callback), implode("/", $splits), $root, $hash);
                                AJXP_XMLWriter::close();
                            }else if(is_array($callback)){
                                call_user_func($callback, implode("/", $splits), $root, $hash);
                            }
                            return;
                        }
                    }else{
                        $parentName = "/".$root."/";
                        $nodes = $rootNodes[$root]["CHILDREN"];
                    }
				}else{
                    $parentName = "/";
                    $nodes = $rootNodes;
                }
                if(isSet($httpVars["file"])){
                    $parentName = $httpVars["dir"]."/";
                    $nodes = array(basename($httpVars["file"]) =>  array("LABEL" => basename($httpVars["file"])));
                }
                if(isSet($nodes)){
                    AJXP_XMLWriter::header();
                    AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="ajxp_conf.1" attributeName="ajxp_label" sortType="String"/></columns>');
                    foreach ($nodes as $key => $data){
                        print '<tree text="'.AJXP_Utils::xmlEntities($data["LABEL"]).'" icon="'.$data["ICON"].'" filename="'.$parentName.$key.'"/>';
                    }
                    AJXP_XMLWriter::close();

                }

			break;
			
			case "stat" :
				
				header("Content-type:application/json");
				print '{"mode":true}';
				return;
				
			break;

            case "create_group":

                $basePath = substr($httpVars["dir"], strlen("/data/users"));
                $gName    = AJXP_Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["group_name"]), AJXP_SANITIZE_ALPHANUM);
                $gLabel   = AJXP_Utils::decodeSecureMagic($httpVars["group_label"]);
                AuthService::createGroup($basePath, $gName, $gLabel);
                AJXP_XMLWriter::header();
                AJXP_XMLWriter::reloadDataNode();
                AJXP_XMLWriter::close();

            break;

            case "create_role":
				$roleId = AJXP_Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["role_id"]), AJXP_SANITIZE_HTML_STRICT);
				if(!strlen($roleId)){
					throw new Exception($mess[349]);
				}
				if(AuthService::getRole($roleId) !== false){
					throw new Exception($mess["ajxp_conf.65"]);
				}
                $r = new AjxpRole($roleId);
                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                    $r->setGroupPath(AuthService::getLoggedUser()->getGroupPath());
                }
				AuthService::updateRole($r);
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.66"], null);
				AJXP_XMLWriter::reloadDataNode("", $httpVars["role_id"]);
				AJXP_XMLWriter::close();				
			break;
			
			case "edit_role" :
				$roleId = SystemTextEncoding::magicDequote($httpVars["role_id"]);
                $roleGroup = false;
                if(strpos($roleId, "AJXP_GRP_") === 0){
                    $groupPath = AuthService::filterBaseGroup(substr($roleId, strlen("AJXP_GRP_/")));
                    $groups = AuthService::listChildrenGroups(dirname($groupPath));
                    $key = "/".basename($groupPath);
                    if(!array_key_exists($key, $groups)){
                        throw new Exception("Cannot find group with this id!");
                    }
                    $roleId = "AJXP_GRP_".$groupPath;
                    $groupLabel = $groups[$key];
                    $roleGroup = true;
                }
                if(strpos($roleId, "AJXP_USR_") === 0){
                    $usrId = str_replace("AJXP_USR_/", "", $roleId);
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($usrId);
                    $role = $userObject->personalRole;
                }else{
                    $role = AuthService::getRole($roleId, $roleGroup);
                }
				if($role === false) {
                    throw new Exception("Cant find role! ");
				}
                if(isSet($httpVars["format"]) && $httpVars["format"] == "json"){
                    HTMLWriter::charsetHeader("application/json");
                    $roleData = $role->getDataArray();
                    $repos = ConfService::getAccessibleRepositories($userObject, true, true, ($userObject == null ? true:false));
                    $data = array(
                        "ROLE" => $roleData,
                        "ALL"  => array(
                            "REPOSITORIES" => $repos
                        )
                    );
                    if(isSet($userObject)){
                        $data["USER"] = array();
                        $data["USER"]["LOCK"] = $userObject->getLock();
                        $data["USER"]["DEFAULT_REPOSITORY"] = $userObject->getPref("force_default_repository");
                        $data["USER"]["PROFILE"] = $userObject->getProfile();
                        $data["ALL"]["PROFILES"] = array("standard|Standard","admin|Administrator","shared|Shared","guest|Guest");
                        $data["USER"]["ROLES"] = array_keys($userObject->getRoles());
                        $data["ALL"]["ROLES"] = array_keys(AuthService::getRolesList(array(), true));
                        if(isSet($userObject->parentRole)){
                            $data["PARENT_ROLE"] = $userObject->parentRole->getDataArray();
                        }
                    }else if(isSet($groupPath)){
                        $data["GROUP"] = array("PATH" => $groupPath, "LABEL" => $groupLabel);
                    }

                    $scope = "role";
                    if($roleGroup) $scope = "group";
                    else if(isSet($userObject)) $scope = "user";
                    $data["SCOPE_PARAMS"] = array();
                    $nodes = AJXP_PluginsService::getInstance()->searchAllManifests("//param[contains(@scope,'".$scope."')]|//global_param[contains(@scope,'".$scope."')]", "node", false, true, true);
                    foreach($nodes as $node){
                        $pId = $node->parentNode->parentNode->attributes->getNamedItem("id")->nodeValue;
                        $origName = $node->attributes->getNamedItem("name")->nodeValue;
                        $node->attributes->getNamedItem("name")->nodeValue = "AJXP_REPO_SCOPE_ALL/".$pId."/".$origName;
                        $nArr = array();
                        foreach($node->attributes as $attrib){
                            $nArr[$attrib->nodeName] = AJXP_XMLWriter::replaceAjxpXmlKeywords($attrib->nodeValue);
                        }
                        $data["SCOPE_PARAMS"][] = $nArr;
                    }

                    echo json_encode($data);
                }
			break;

            case "post_json_role" :

                $roleId = SystemTextEncoding::magicDequote($httpVars["role_id"]);
                $roleGroup = false;
                if(strpos($roleId, "AJXP_GRP_") === 0){
                    $groupPath = AuthService::filterBaseGroup(substr($roleId, strlen("AJXP_GRP_")));
                    $roleId = "AJXP_GRP_".$groupPath;
                    $groups = AuthService::listChildrenGroups(dirname($groupPath));
                    $key = "/".basename($groupPath);
                    if(!array_key_exists($key, $groups)){
                        throw new Exception("Cannot find group with this id!");
                    }
                    $groupLabel = $groups[$key];
                    $roleGroup = true;
                }
                if(strpos($roleId, "AJXP_USR_") === 0){
                    $usrId = str_replace("AJXP_USR_/", "", $roleId);
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($usrId);
                    $originalRole = $userObject->personalRole;
                }else{
                    // second param = create if not exists.
                    $originalRole = AuthService::getRole($roleId, $roleGroup);
                }
                if($originalRole === false) {
                    throw new Exception("Cant find role! ");
                }

                $jsonData = AJXP_Utils::decodeSecureMagic($httpVars["json_data"]);
                $data = json_decode($jsonData, true);
                $roleData = $data["ROLE"];
                $forms = $data["FORMS"];
                $binariesContext = array();
                if(isset($userObject)){
                    $binariesContext = array("USER" => $userObject->getId());
                }
                foreach($forms as $repoScope => $plugData){
                    foreach($plugData as $plugId => $formsData){
                        $parsed = array();
                        AJXP_Utils::parseStandardFormParameters(
                            $formsData,
                            $parsed,
                            ($userObject!=null?$usrId:null),
                            "ROLE_PARAM_",
                            $binariesContext
                        );
                        $roleData["PARAMETERS"][$repoScope][$plugId] = $parsed;
                    }
                }
                if(isSet($userObject) && isSet($data["USER"]) && isSet($data["USER"]["PROFILE"])){
                    $userObject->setAdmin(($data["USER"]["PROFILE"] == "admin"));
                    $userObject->setProfile($data["USER"]["PROFILE"]);
                }
                if(isSet($data["GROUP_LABEL"]) && isSet($groupLabel) && $groupLabel != $data["GROUP_LABEL"]){
                    ConfService::getConfStorageImpl()->relabelGroup($groupPath, $data["GROUP_LABEL"]);
                }

                $output = array();
                try{
                    $originalRole->bunchUpdate($roleData);
                    if(isSet($userObject)){
                        $userObject->personalRole = $originalRole;
                        $userObject->save("superuser");
                        //AuthService::updateRole($originalRole, $userObject);
                    }else{
                        AuthService::updateRole($originalRole);
                    }
                    $output = array("ROLE" => $originalRole->getDataArray(), "SUCCESS" => true);
                }catch (Exception $e){
                    $output = array("ERROR" => $e->getMessage());
                }
                HTMLWriter::charsetHeader("application/json");
                echo(json_encode($output));

            break;


            case "user_set_lock" :

                $userId = AJXP_Utils::decodeSecureMagic($httpVars["user_id"]);
                $lock = ($httpVars["lock"] == "true" ? true : false);
                $lockType = $httpVars["lock_type"];
                if(AuthService::userExists($userId)){
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($userId);
                    if($lock){
                        $userObject->setLock($lockType);
                    }else{
                        $userObject->removeLock();
                    }
                    $userObject->save("superuser");
                }

            break;

			case "create_user" :
				
				if(!isset($httpVars["new_user_login"]) || $httpVars["new_user_login"] == "" ||!isset($httpVars["new_user_pwd"]) || $httpVars["new_user_pwd"] == "")
				{
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.61"]);
					AJXP_XMLWriter::close();
					return;						
				}
				$new_user_login = AJXP_Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["new_user_login"]), AJXP_SANITIZE_EMAILCHARS);
				if(AuthService::userExists($new_user_login) || AuthService::isReservedUserId($new_user_login))
				{
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.43"]);
					AJXP_XMLWriter::close();
					return;									
				}

                AuthService::createUser($new_user_login, $httpVars["new_user_pwd"]);
                $confStorage = ConfService::getConfStorageImpl();
				$newUser = $confStorage->createUserObject($new_user_login);
                $basePath = AuthService::getLoggedUser()->getGroupPath();
                if(empty ($basePath)) $basePath = "/";
                if(!empty($httpVars["group_path"])){
                    $newUser->setGroupPath($basePath.ltrim($httpVars["group_path"], "/"));
                }else{
                    $newUser->setGroupPath($basePath);
                }

				$newUser->save("superuser");
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.44"], null);
				AJXP_XMLWriter::reloadDataNode("", $new_user_login);
				AJXP_XMLWriter::close();
														
			break;
								
			case "change_admin_right" :
				$userId = $httpVars["user_id"];
				if(!AuthService::userExists($userId)){
					throw new Exception("Invalid user id!");
				}				
				$confStorage = ConfService::getConfStorageImpl();		
				$user = $confStorage->createUserObject($userId);
				$user->setAdmin(($httpVars["right_value"]=="1"?true:false));
				$user->save("superuser");
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.45"].$httpVars["user_id"], null);
				AJXP_XMLWriter::reloadDataNode();
				AJXP_XMLWriter::close();
				
			break;
		
			case "update_user_right" :
				if(!isSet($httpVars["user_id"]) 
					|| !isSet($httpVars["repository_id"]) 
					|| !isSet($httpVars["right"])
					|| !AuthService::userExists($httpVars["user_id"]))
				{
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.61"]);
					print("<update_checkboxes user_id=\"".$httpVars["user_id"]."\" repository_id=\"".$httpVars["repository_id"]."\" read=\"old\" write=\"old\"/>");
					AJXP_XMLWriter::close();
					return;
				}
				$confStorage = ConfService::getConfStorageImpl();		
				$user = $confStorage->createUserObject($httpVars["user_id"]);
				$user->personalRole->setAcl(AJXP_Utils::sanitize($httpVars["repository_id"], AJXP_SANITIZE_ALPHANUM), AJXP_Utils::sanitize($httpVars["right"], AJXP_SANITIZE_ALPHANUM));
				$user->save();
				$loggedUser = AuthService::getLoggedUser();
				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.46"].$httpVars["user_id"], null);
				print("<update_checkboxes user_id=\"".$httpVars["user_id"]."\" repository_id=\"".$httpVars["repository_id"]."\" read=\"".$user->canRead($httpVars["repository_id"])."\" write=\"".$user->canWrite($httpVars["repository_id"])."\"/>");
				AJXP_XMLWriter::reloadRepositoryList();
				AJXP_XMLWriter::close();
				return ;
			break;

            case "user_update_group":

                $userSelection = new UserSelection();
                $userSelection->initFromHttpVars($httpVars);
                $dir = $httpVars["dir"];
                $dest = $httpVars["dest"];
                if(strpos($dir, "/data/users",0)!==0 || strpos($dest, "/data/users",0)!==0){
                    break;
                }
                $groupPath = substr($dest, strlen("/data/users"));

                $confStorage = ConfService::getConfStorageImpl();

                foreach($userSelection->getFiles() as $selectedUser){
                    $userId = basename($selectedUser);
                    if(!AuthService::userExists($userId)){
                        continue;
                    }
                    $user = $confStorage->createUserObject($userId);
                    $basePath = (AuthService::getLoggedUser()!=null ? AuthService::getLoggedUser()->getGroupPath(): "/");
                    if(empty ($basePath)) $basePath = "/";
                    if(!empty($groupPath)){
                        $user->setGroupPath(rtrim($basePath, "/")."/".ltrim($groupPath, "/"));
                    }else{
                        $user->setGroupPath($basePath);
                    }
                    $user->save("superuser");
                }
                AJXP_XMLWriter::header();
                AJXP_XMLWriter::reloadDataNode();
                AJXP_XMLWriter::reloadDataNode($dest, $userId);
                AJXP_XMLWriter::close();

                break;
		
			case "user_add_role" : 
			case "user_delete_role":
			
				if(!isSet($httpVars["user_id"]) || !isSet($httpVars["role_id"]) || !AuthService::userExists($httpVars["user_id"]) || !AuthService::getRole($httpVars["role_id"])){
					throw new Exception($mess["ajxp_conf.61"]);
				}
				if($action == "user_add_role"){
					$act = "add";
					$messId = "73";
				}else{
					$act = "remove";
					$messId = "74";
				}
				$this->updateUserRole($httpVars["user_id"], $httpVars["role_id"], $act);
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.".$messId].$httpVars["user_id"], null);
				AJXP_XMLWriter::close();
				return ;
				
			break;
			
			case "batch_users_roles" : 
				
				$confStorage = ConfService::getConfStorageImpl();	
				$selection = new UserSelection();
				$selection->initFromHttpVars($httpVars);
				$files = $selection->getFiles();
				$detectedRoles = array();
				
				if(isSet($httpVars["role_id"]) && isset($httpVars["update_role_action"])){
					$update = $httpVars["update_role_action"];
					$roleId = $httpVars["role_id"];
					if(AuthService::getRole($roleId) === false){
						throw new Exception("Invalid role id");
					}
				}
				foreach ($files as $index => $file){
					$userId = basename($file);
					if(isSet($update)){
						$userObject = $this->updateUserRole($userId, $roleId, $update);
					}else{
						$userObject = $confStorage->createUserObject($userId);
					}
					if($userObject->hasParent()){
						unset($files[$index]);
						continue;
					}
					$userRoles = $userObject->getRoles();
					foreach ($userRoles as $roleIndex => $bool){
						if(!isSet($detectedRoles[$roleIndex])) $detectedRoles[$roleIndex] = 0;
						if($bool === true) $detectedRoles[$roleIndex] ++;
					}
				}
				$count = count($files);
				AJXP_XMLWriter::header("admin_data");
				print("<user><ajxp_roles>");
				foreach ($detectedRoles as $roleId => $roleCount){
					if($roleCount < $count) continue;
					print("<role id=\"$roleId\"/>");
				}				
				print("</ajxp_roles></user>");
				print("<ajxp_roles>");
				foreach (AuthService::getRolesList(array(), !$this->listSpecialRoles) as $roleId => $roleObject){
					print("<role id=\"$roleId\"/>");
				}
				print("</ajxp_roles>");				
				AJXP_XMLWriter::close("admin_data");
			
			break;
			
			case "save_custom_user_params" : 
				$userId = $httpVars["user_id"];
				if($userId == $loggedUser->getId()){
					$user = $loggedUser;
				}else{
					$confStorage = ConfService::getConfStorageImpl();		
					$user = $confStorage->createUserObject($userId);
				}
				$custom = $user->getPref("CUSTOM_PARAMS");
				if(!is_array($custom)) $custom = array();
				
				$options = $custom;
				$this->parseParameters($httpVars, $options, $userId);
				$custom = $options;
				$user->setPref("CUSTOM_PARAMS", $custom);
				$user->save();
				
				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.47"].$httpVars["user_id"], null);
				AJXP_XMLWriter::close();
					
			break;
			
			case "save_repository_user_params" : 
				$userId = $httpVars["user_id"];
				if($userId == $loggedUser->getId()){
					$user = $loggedUser;
				}else{
					$confStorage = ConfService::getConfStorageImpl();		
					$user = $confStorage->createUserObject($userId);
				}
				$wallet = $user->getPref("AJXP_WALLET");
				if(!is_array($wallet)) $wallet = array();
				$repoID = $httpVars["repository_id"];
				if(!array_key_exists($repoID, $wallet)){
					$wallet[$repoID] = array();
				}
				$options = $wallet[$repoID];
				$this->parseParameters($httpVars, $options, $userId);
				$wallet[$repoID] = $options;
				$user->setPref("AJXP_WALLET", $wallet);
				$user->save();
				
				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.47"].$httpVars["user_id"], null);
				AJXP_XMLWriter::close();
					
			break;
			
			case "update_user_pwd" : 
				if(!isSet($httpVars["user_id"]) || !isSet($httpVars["user_pwd"]) || !AuthService::userExists($httpVars["user_id"]) || trim($httpVars["user_pwd"]) == "")
				{
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.61"]);
					AJXP_XMLWriter::close();
					return;			
				}
				$res = AuthService::updatePassword($httpVars["user_id"], $httpVars["user_pwd"]);
				AJXP_XMLWriter::header();
				if($res === true)
				{
					AJXP_XMLWriter::sendMessage($mess["ajxp_conf.48"].$httpVars["user_id"], null);
				}
				else 
				{
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.49"]." : $res");
				}
				AJXP_XMLWriter::close();
										
			break;

            case "save_user_preference":

                if(!isSet($httpVars["user_id"]) || !AuthService::userExists($httpVars["user_id"]) ){
                    throw new Exception($mess["ajxp_conf.61"]);
                }
                $userId = $httpVars["user_id"];
                if($userId == $loggedUser->getId()){
                    $userObject = $loggedUser;
                }else{
                    $confStorage = ConfService::getConfStorageImpl();
                    $userObject = $confStorage->createUserObject($userId);
                }
                $i = 0;
                while(isSet($httpVars["pref_name_".$i]) && isSet($httpVars["pref_value_".$i]))
                {
                    $prefName = AJXP_Utils::sanitize($httpVars["pref_name_".$i], AJXP_SANITIZE_ALPHANUM);
                    $prefValue = AJXP_Utils::sanitize(SystemTextEncoding::magicDequote(($httpVars["pref_value_".$i])));
                    if($prefName == "password") continue;
                    if($prefName != "pending_folder" && $userObject == null){
                        $i++;
                        continue;
                    }
                    $userObject->setPref($prefName, $prefValue);
                    $userObject->save("user");
                    $i++;
                }
                AJXP_XMLWriter::header();
                AJXP_XMLWriter::sendMessage("Succesfully saved user preference", null);
                AJXP_XMLWriter::close();

            break;

			case  "get_drivers_definition":

				AJXP_XMLWriter::header("drivers", array("allowed" => $currentUserIsGroupAdmin ? "false" : "true"));
				print(AJXP_XMLWriter::replaceAjxpXmlKeywords(ConfService::availableDriversToXML("param", "", true)));
				AJXP_XMLWriter::close("drivers");
				
				
			break;
			
			case  "get_templates_definition":
				
				AJXP_XMLWriter::header("repository_templates");
				$repositories = ConfService::getRepositoriesList();
				foreach ($repositories as $repo){
					if(!$repo->isTemplate) continue;
					$repoId = $repo->getUniqueId();
					$repoLabel = $repo->getDisplay();
					$repoType = $repo->getAccessType();
					print("<template repository_id=\"$repoId\" repository_label=\"$repoLabel\" repository_type=\"$repoType\">");
					foreach($repo->getOptionsDefined() as $optionName){
						print("<option name=\"$optionName\"/>");
					}
					print("</template>");
				}
				AJXP_XMLWriter::close("repository_templates");
				
				
			break;
			
			case "create_repository" : 
			
				$options = array();
				$repDef = $httpVars;
                $isTemplate = isSet($httpVars["sf_checkboxes_active"]);
                unset($repDef["get_action"]);
                unset($repDef["sf_checkboxes_active"]);
				$this->parseParameters($repDef, $options, null, true);
				if(count($options)){
					$repDef["DRIVER_OPTIONS"] = $options;
				}
				if(strstr($repDef["DRIVER"], "ajxp_template_") !== false){
					$templateId = substr($repDef["DRIVER"], 14);
					$templateRepo = ConfService::getRepositoryById($templateId);
					$newRep = $templateRepo->createTemplateChild($repDef["DISPLAY"], $repDef["DRIVER_OPTIONS"]);
				}else{
                    if($currentUserIsGroupAdmin){
                        throw new Exception("You are not allowed to create a repository from a driver. Use a template instead.");
                    }
                    $pServ = AJXP_PluginsService::getInstance();
                    $driver = $pServ->getPluginByTypeName("access", $repDef["DRIVER"]);

					$newRep = ConfService::createRepositoryFromArray(0, $repDef);
                    $testFile = $driver->getBaseDir()."/test.".$newRep->getAccessType()."Access.php";
					if(!$isTemplate && is_file($testFile))
					{
					    //chdir(AJXP_TESTS_FOLDER."/plugins");
						include($testFile);
						$className = $newRep->getAccessType()."AccessTest";
						$class = new $className();
						$result = $class->doRepositoryTest($newRep);
						if(!$result){
							AJXP_XMLWriter::header();
							AJXP_XMLWriter::sendMessage(null, $class->failedInfo);
							AJXP_XMLWriter::close();
							return;
						}
					}
                    // Apply default metasource if any
                    if($driver != null && $driver->getConfigs()!=null ){
                        $confs = $driver->getConfigs();
                        if(!empty($confs["DEFAULT_METASOURCES"])){
                            $metaIds = AJXP_Utils::parseCSL($confs["DEFAULT_METASOURCES"]);
                            $metaSourceOptions = array();
                            foreach($metaIds as $metaID){
                                $metaPlug = $pServ->getPluginById($metaID);
                                if($metaPlug == null) continue;
                                $pNodes = $metaPlug->getManifestRawContent("//param[@default]", "nodes");
                                $defaultParams = array();
                                foreach($pNodes as $domNode){
                                    $defaultParams[$domNode->getAttribute("name")] = $domNode->getAttribute("default");
                                }
                                $metaSourceOptions[$metaID] = $defaultParams;
                            }
                            $newRep->addOption("META_SOURCES", $metaSourceOptions);
                        }
                    }
				}

                if ($this->repositoryExists($newRep->getDisplay()))
                {
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.50"]);
					AJXP_XMLWriter::close();
					return;
                }
                if($isTemplate){
                    $newRep->isTemplate = true;
                }
                if($currentUserIsGroupAdmin){
                    $newRep->setGroupPath(AuthService::getLoggedUser()->getGroupPath());
                }
				$res = ConfService::addRepository($newRep);
				AJXP_XMLWriter::header();
				if($res == -1){
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.51"]);
				}else{
					$loggedUser = AuthService::getLoggedUser();
					$loggedUser->personalRole->setAcl($newRep->getUniqueId(), "rw");
					$loggedUser->save("superuser");
					AuthService::updateUser($loggedUser);
					
					AJXP_XMLWriter::sendMessage($mess["ajxp_conf.52"], null);
					AJXP_XMLWriter::reloadDataNode("", $newRep->getUniqueId());
					AJXP_XMLWriter::reloadRepositoryList();
				}
				AJXP_XMLWriter::close();
				
				
			
			break;
			
			case "edit_repository" : 
				$repId = $httpVars["repository_id"];
				$repList = ConfService::getRepositoriesList();
				//print_r($repList);
				if(!isSet($repList[$repId])){
					throw new Exception("Cannot find repository with id $repId");
				}
				$repository = $repList[$repId];
                if(!AuthService::canAdministrate($repository)){
                    throw new Exception("You are not allowed to edit this repository!");
                }
				$pServ = AJXP_PluginsService::getInstance();
				$plug = $pServ->getPluginById("access.".$repository->accessType);
				if($plug == null){
					throw new Exception("Cannot find access driver (".$repository->accessType.") for repository!");
				}				
				AJXP_XMLWriter::header("admin_data");		
				$slug = $repository->getSlug();
				if($slug == "" && $repository->isWriteable()){
					$repository->setSlug();
					ConfService::replaceRepository($repId, $repository);
				}
                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath() != null){
                    $rgp = $repository->getGroupPath();
                    if($rgp == null) $rgp = "/";
                    if(strlen($rgp) < strlen(AuthService::getLoggedUser()->getGroupPath())) {
                        $repository->setWriteable(false);
                    }
                }
				$nested = array();
				print("<repository index=\"$repId\"");
				foreach ($repository as $name => $option){
					if(strstr($name, " ")>-1) continue;
					if(!is_array($option)){					
						if(is_bool($option)){
							$option = ($option?"true":"false");
						}
						print(" $name=\"".SystemTextEncoding::toUTF8(AJXP_Utils::xmlEntities($option))."\" ");
					}else if(is_array($option)){
						$nested[] = $option;
					}
				}
				if(count($nested)){
					print(">");
					foreach ($nested as $option){
						foreach ($option as $key => $optValue){
							if(is_array($optValue) && count($optValue)){
								print("<param name=\"$key\"><![CDATA[".json_encode($optValue)."]]></param>");
							}else{
								if(is_bool($optValue)){
									$optValue = ($optValue?"true":"false");
								}
								print("<param name=\"$key\" value=\"$optValue\"/>");
							}
						}
					}
					// Add SLUG
					if(!$repository->isTemplate) print("<param name=\"AJXP_SLUG\" value=\"".$repository->getSlug()."\"/>");
                    if($repository->getGroupPath() != null) {
                        $basePath = "/";
                        if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                            $basePath = AuthService::getLoggedUser()->getGroupPath();
                        }
                        $groupPath = $repository->getGroupPath();
                        if($basePath != "/") $groupPath = substr($repository->getGroupPath(), strlen($basePath));
                        print("<param name=\"AJXP_GROUP_PATH\" value=\"".$groupPath."\"/>");
                    }
					print("</repository>");
				}else{
					print("/>");
				}
				if($repository->hasParent()){
					$parent = ConfService::getRepositoryById($repository->getParentId());
					if(isSet($parent) && $parent->isTemplate){
						$parentLabel = $parent->getDisplay();
						$parentType = $parent->getAccessType();
						print("<template repository_id=\"".$repository->getParentId()."\" repository_label=\"$parentLabel\" repository_type=\"$parentType\">");
						foreach($parent->getOptionsDefined() as $parentOptionName){
							print("<option name=\"$parentOptionName\"/>");
						}
						print("</template>");						
					}
				}
				$manifest = $plug->getManifestRawContent("server_settings/param");
                $manifest = AJXP_XMLWriter::replaceAjxpXmlKeywords($manifest);
				print("<ajxpdriver name=\"".$repository->accessType."\">$manifest</ajxpdriver>");
				print("<metasources>");
				$metas = $pServ->getPluginsByType("metastore");
				$metas = array_merge($metas, $pServ->getPluginsByType("meta"));
                $metas = array_merge($metas, $pServ->getPluginsByType("index"));
				foreach ($metas as $metaPlug){
					print("<meta id=\"".$metaPlug->getId()."\" label=\"".AJXP_Utils::xmlEntities($metaPlug->getManifestLabel())."\">");
					$manifest = $metaPlug->getManifestRawContent("server_settings/param");
                    $manifest = AJXP_XMLWriter::replaceAjxpXmlKeywords($manifest);
					print($manifest);
					print("</meta>");
				}
				print("</metasources>");
				AJXP_XMLWriter::close("admin_data");
				return ;
			break;
			
			case "edit_repository_label" : 
			case "edit_repository_data" : 
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				$res = 0;
				if(isSet($httpVars["newLabel"])){
					$newLabel = AJXP_Utils::decodeSecureMagic($httpVars["newLabel"]);
                    if ($this->repositoryExists($newLabel))
                    {
		     			AJXP_XMLWriter::header();
			    		AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.50"]);
				    	AJXP_XMLWriter::close();
					    return;
                    }
					$repo->setDisplay($newLabel);                    
					$res = ConfService::replaceRepository($repId, $repo);
				}else{
					$options = array();
					$this->parseParameters($httpVars, $options, null, true);
					if(count($options)){
						foreach ($options as $key=>$value) {
							if($key == "AJXP_SLUG"){
								$repo->setSlug($value);
								continue;
							}elseif($key == "AJXP_GROUP_PATH"){
                                $basePath = "/";
                                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                                    $basePath = AuthService::getLoggedUser()->getGroupPath();
                                }
                                $value =  AJXP_Utils::securePath(rtrim($basePath, "/")."/".ltrim($value, "/"));
                                $repo->setGroupPath($value);
                                continue;
                            }
							$repo->addOption($key, $value);
						}
					}
                    if($repo->getOption("DEFAULT_RIGHTS")){
                        $gp = $repo->getGroupPath();
                        if(empty($gp) || $gp == "/"){
                            $defRole = AuthService::getRole("ROOT_ROLE");
                        }else{
                            $defRole = AuthService::getRole("AJXP_GRP_".$gp, true);
                        }
                        if($defRole !== false){
                            $defRole->setAcl($repId, $repo->getOption("DEFAULT_RIGHTS"));
                            AuthService::updateRole($defRole);
                        }
                    }
					if(is_file(AJXP_TESTS_FOLDER."/plugins/test.ajxp_".$repo->getAccessType().".php")){
					    chdir(AJXP_TESTS_FOLDER."/plugins");
						include(AJXP_TESTS_FOLDER."/plugins/test.ajxp_".$repo->getAccessType().".php");
						$className = "ajxp_".$repo->getAccessType();
						$class = new $className();
						$result = $class->doRepositoryTest($repo);
						if(!$result){
							AJXP_XMLWriter::header();
							AJXP_XMLWriter::sendMessage(null, $class->failedInfo);
							AJXP_XMLWriter::close();
							return;
						}
					}
					
					ConfService::replaceRepository($repId, $repo);
				}
				AJXP_XMLWriter::header();
				if($res == -1){
					AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.53"]);
				}else{
					AJXP_XMLWriter::sendMessage($mess["ajxp_conf.54"], null);					
					AJXP_XMLWriter::reloadDataNode("", (isSet($httpVars["newLabel"])?$repId:false));
					AJXP_XMLWriter::reloadRepositoryList();
				}
				AJXP_XMLWriter::close();		
				
			break;
			
			case "add_meta_source" : 
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new Exception("Invalid repository id! $repId");
				}
				$metaSourceType = AJXP_Utils::sanitize($httpVars["new_meta_source"], AJXP_SANITIZE_ALPHANUM);
				$options = array();
				$this->parseParameters($httpVars, $options, null, true);
				$repoOptions = $repo->getOption("META_SOURCES");
				if(is_array($repoOptions) && isSet($repoOptions[$metaSourceType])){
					throw new Exception($mess["ajxp_conf.55"]);
				}
				if(!is_array($repoOptions)){
					$repoOptions = array();
				}
				$repoOptions[$metaSourceType] = $options;
				uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
				$repo->addOption("META_SOURCES", $repoOptions);
				ConfService::replaceRepository($repId, $repo);
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.56"],null);
				AJXP_XMLWriter::close();
			break;
						
			case "delete_meta_source" : 
			
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new Exception("Invalid repository id! $repId");
				}
				$metaSourceId = $httpVars["plugId"];
				$repoOptions = $repo->getOption("META_SOURCES");
				if(is_array($repoOptions) && array_key_exists($metaSourceId, $repoOptions)){
					unset($repoOptions[$metaSourceId]);
					uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
					$repo->addOption("META_SOURCES", $repoOptions);
					ConfService::replaceRepository($repId, $repo);
				}
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.57"],null);
				AJXP_XMLWriter::close();

			break;
			
			case "edit_meta_source" : 
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new Exception("Invalid repository id! $repId");
				}				
				$metaSourceId = $httpVars["plugId"];
				$options = array();
				$this->parseParameters($httpVars, $options, null, true);
				$repoOptions = $repo->getOption("META_SOURCES");
				if(!is_array($repoOptions)){
					$repoOptions = array();
				}
				$repoOptions[$metaSourceId] = $options;
				uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
				$repo->addOption("META_SOURCES", $repoOptions);
				ConfService::replaceRepository($repId, $repo);
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.58"],null);
				AJXP_XMLWriter::close();
			break;
									
				
			case "delete" :
				if(isSet($httpVars["repository_id"])){
					$repId = $httpVars["repository_id"];					
					$res = ConfService::deleteRepository($repId);
					AJXP_XMLWriter::header();
					if($res == -1){
						AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.51"]);
					}else{
						AJXP_XMLWriter::sendMessage($mess["ajxp_conf.59"], null);						
						AJXP_XMLWriter::reloadDataNode();
						AJXP_XMLWriter::reloadRepositoryList();
					}
					AJXP_XMLWriter::close();		
					return;
				}else if(isSet($httpVars["shared_file"])){
					AJXP_XMLWriter::header();
					$element = basename($httpVars["shared_file"]);
					$dlFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
					$publicletData = $this->loadPublicletData($dlFolder."/".$element.".php");
					unlink($dlFolder."/".$element.".php");
					AJXP_XMLWriter::sendMessage($mess["ajxp_shared.13"], null);
					AJXP_XMLWriter::reloadDataNode();
					AJXP_XMLWriter::close();					
				}else if(isSet($httpVars["role_id"])){
					$roleId = $httpVars["role_id"];
					if(AuthService::getRole($roleId) === false){
						throw new Exception($mess["ajxp_conf.67"]);
					}
					AuthService::deleteRole($roleId);
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage($mess["ajxp_conf.66"], null);
					AJXP_XMLWriter::reloadDataNode();
					AJXP_XMLWriter::close();
                }else if(isSet($httpVars["group"])){
                    $groupPath = $httpVars["group"];
                    $basePath = substr(dirname($groupPath), strlen("/data/users"));
                    $gName = basename($groupPath);
                    AuthService::deleteGroup($basePath, $gName);
                    AJXP_XMLWriter::header();
                    AJXP_XMLWriter::reloadDataNode();
                    AJXP_XMLWriter::close();
                }else{
					if(!isset($httpVars["user_id"]) || $httpVars["user_id"]==""
						|| AuthService::isReservedUserId($httpVars["user_id"])
						|| $loggedUser->getId() == $httpVars["user_id"])
					{
						AJXP_XMLWriter::header();
						AJXP_XMLWriter::sendMessage(null, $mess["ajxp_conf.61"]);
						AJXP_XMLWriter::close();
					}
					$res = AuthService::deleteUser($httpVars["user_id"]);
					AJXP_XMLWriter::header();
					AJXP_XMLWriter::sendMessage($mess["ajxp_conf.60"], null);
					AJXP_XMLWriter::reloadDataNode();
					AJXP_XMLWriter::close();
					
				}
			break;
			
			case "clear_expired" :
				
				$deleted = $this->clearExpiredFiles();
				AJXP_XMLWriter::header();
				if(count($deleted)){
					AJXP_XMLWriter::sendMessage(sprintf($mess["ajxp_shared.23"], count($deleted).""), null);
					AJXP_XMLWriter::reloadDataNode();					
				}else{
					AJXP_XMLWriter::sendMessage($mess["ajxp_shared.24"], null);
				}
				AJXP_XMLWriter::close();
				
			break;			
			
			case "get_plugin_manifest" : 

				$ajxpPlugin = AJXP_PluginsService::getInstance()->getPluginById($httpVars["plugin_id"]);
				AJXP_XMLWriter::header("admin_data");
				echo(AJXP_XMLWriter::replaceAjxpXmlKeywords($ajxpPlugin->getManifestRawContent()));
				$definitions = $ajxpPlugin->getConfigsDefinitions();
				$values = $ajxpPlugin->getConfigs();
                if(!is_array($values)) $values = array();
                echo("<plugin_settings_values>");
                foreach($values as $key => $value){
                    $attribute = true;
                    if($definitions[$key]["type"] == "array" && is_array($value)){
                        $value = implode(",", $value);
                    }else if($definitions[$key]["type"] == "boolean"){
                        $value = ($value === true || $value === "true" || $value == 1?"true":"false");
                    }else if($definitions[$key]["type"] == "textarea"){
                        //$value = str_replace("\\n", "\n", $value);
                        $attribute = false;
                    }
                    if($attribute){
                        echo("<param name=\"$key\" value=\"".AJXP_Utils::xmlEntities($value)."\"/>");
                    }else{
                        echo("<param name=\"$key\" cdatavalue=\"true\"><![CDATA[".$value."]]></param>");
                    }
                }
                if($ajxpPlugin->getType() != "core"){
                    echo("<param name=\"AJXP_PLUGIN_ENABLED\" value=\"".($ajxpPlugin->isEnabled()?"true":"false")."\"/>");
                }
                echo("</plugin_settings_values>");
                echo("<plugin_doc><![CDATA[<p>".$ajxpPlugin->getPluginInformationHTML("Charles du Jeu", "http://ajaxplorer.info/plugins/")."</p>");
                if(file_exists($ajxpPlugin->getBaseDir()."/plugin_doc.html")){
                    echo(file_get_contents($ajxpPlugin->getBaseDir()."/plugin_doc.html"));
                }
                echo("]]></plugin_doc>");
				AJXP_XMLWriter::close("admin_data");
				
			break;
			
			case "edit_plugin_options":
				
				$options = array();
				$this->parseParameters($httpVars, $options, null, true);
				$confStorage = ConfService::getConfStorageImpl();
				$confStorage->savePluginConfig($httpVars["plugin_id"], $options);
				@unlink(AJXP_PLUGINS_CACHE_FILE);
				@unlink(AJXP_PLUGINS_REQUIRES_FILE);				
				@unlink(AJXP_PLUGINS_MESSAGES_FILE);
				AJXP_XMLWriter::header();
				AJXP_XMLWriter::sendMessage($mess["ajxp_conf.97"], null);
				AJXP_XMLWriter::reloadDataNode();
				AJXP_XMLWriter::close();
				
				
			break;

			default:
			break;
		}

		return;
	}
	
	
	function listPlugins($dir, $root = NULL){
        $dir = "/$dir";
		AJXP_Logger::logAction("Listing plugins"); // make sure that the logger is started!
		$pServ = AJXP_PluginsService::getInstance();
		$activePlugins = $pServ->getActivePlugins();
		$types = $pServ->getDetectedPlugins();
		$uniqTypes = array("core");
		if($dir == "/plugins"){
			AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="ajxp_conf.plugins_folder">
			<column messageId="ajxp_conf.101" attributeName="ajxp_label" sortType="String"/>
			</columns>');		
			ksort($types);
			foreach( $types as $t => $tPlugs){
				if(in_array($t, $uniqTypes))continue;
				$meta = array(
					"icon" 		=> "folder_development.png",					
					"plugin_id" => $t
				);
				AJXP_XMLWriter::renderNode("/$root/plugins/".$t, ucfirst($t), false, $meta);
			}
		}else if($dir == "/core"){
			AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" switchDisplayMode="list"  template_name="ajxp_conf.plugins">
			<column messageId="ajxp_conf.101" attributeName="ajxp_label" sortType="String"/>
			<column messageId="ajxp_conf.102" attributeName="plugin_id" sortType="String"/>
			<column messageId="ajxp_conf.103" attributeName="plugin_description" sortType="String"/>
			</columns>');		
			$mess = ConfService::getMessages();
			foreach($uniqTypes as $type){
				if(!isset($types[$type])) continue;				
				foreach($types[$type] as $pId => $pObject){
					$meta = array(				
						"icon" 		=> ($type == "core"?"preferences_desktop.png":"preferences_plugin.png"),
						"ajxp_mime" => "ajxp_plugin",
						"plugin_id" => $pObject->getId(),						
						"plugin_description" => $pObject->getManifestDescription()
					);
					if($type == "core"){
						if($pObject->getId() == "core.ajaxplorer"){
							$label = "AjaXplorer Core";
						}else{
							$label =  sprintf($mess["ajxp_conf.100"], $pObject->getName());
						}
					}else{
						if($activePlugins[$pObject->getId()] !== true) continue;
						$label = $pObject->getManifestLabel();
					}
					AJXP_XMLWriter::renderNode("/$root/plugins/".$pObject->getId(), $label, true, $meta);
				}				
			}
		}else{
			$split = explode("/", $dir);
			if(empty($split[0])) array_shift($split);
			$type = $split[1];
			AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" switchDisplayMode="full" template_name="ajxp_conf.plugin_detail">
			<column messageId="ajxp_conf.101" attributeName="ajxp_label" sortType="String" defaultWidth="10%"/>
			<column messageId="ajxp_conf.102" attributeName="plugin_id" sortType="String" defaultWidth="10%"/>
			<column messageId="ajxp_conf.103" attributeName="plugin_description" sortType="String" defaultWidth="60%"/>
			<column messageId="ajxp_conf.104" attributeName="enabled" sortType="String" defaultWidth="10%"/>
			<column messageId="ajxp_conf.105" attributeName="can_active" sortType="String" defaultWidth="10%"/>
			</columns>');
            $mess = ConfService::getMessages();
			foreach($types[$type] as $pId => $pObject){
				$errors = "OK";
				try{
					$pObject->performChecks();
				}catch(Exception $e){
					$errors = "ERROR : ".$e->getMessage();
				}
				$meta = array(				
					"icon" 		=> "preferences_plugin.png",
					"ajxp_mime" => "ajxp_plugin",
					"can_active"	=> $errors,
					"enabled"	=> ($pObject->isEnabled()?$mess[440]:$mess[441]),
					"plugin_id" => $pObject->getId(),
					"plugin_description" => $pObject->getManifestDescription()
				);
				AJXP_XMLWriter::renderNode("/$root/plugins/".$pObject->getId(), $pObject->getManifestLabel(), true, $meta);
			}
		}
	}
	
	function listUsers($root, $child, $hashValue = null){
        $columns = '<columns switchGridMode="filelist" template_name="ajxp_conf.users">
        			<column messageId="ajxp_conf.6" attributeName="ajxp_label" sortType="String" defaultWidth="40%"/>
        			<column messageId="ajxp_conf.7" attributeName="isAdmin" sortType="String" defaultWidth="10%"/>
        			<column messageId="ajxp_conf.70" attributeName="ajxp_roles" sortType="String" defaultWidth="15%"/>
        			<column messageId="ajxp_conf.62" attributeName="rights_summary" sortType="String" defaultWidth="15%"/>
        			</columns>';
        if(AuthService::driverSupportsAuthSchemes()){
            $columns = '<columns switchGridMode="filelist" template_name="ajxp_conf.users">
            			<column messageId="ajxp_conf.6" attributeName="ajxp_label" sortType="String" defaultWidth="40%"/>
            			<column messageId="ajxp_conf.115" attributeName="auth_scheme" sortType="String" defaultWidth="5%"/>
            			<column messageId="ajxp_conf.7" attributeName="isAdmin" sortType="String" defaultWidth="5%"/>
            			<column messageId="ajxp_conf.70" attributeName="ajxp_roles" sortType="String" defaultWidth="15%"/>
            			<column messageId="ajxp_conf.62" attributeName="rights_summary" sortType="String" defaultWidth="15%"/>
            </columns>';
        }
		AJXP_XMLWriter::sendFilesListComponentConfig($columns);
		if(!AuthService::usersEnabled()) return ;
        $count = AuthService::authCountUsers();
        $USER_PER_PAGE = 50;
        if(empty($hashValue)) $hashValue = 1;
        if($root == "users") $baseGroup = "/";
        else $baseGroup = substr($root, strlen("users"));
        if(AuthService::authSupportsPagination() && $count > $USER_PER_PAGE){
            $offset = ($hashValue - 1) * $USER_PER_PAGE;
            AJXP_XMLWriter::renderPaginationData($count, $hashValue, ceil($count/$USER_PER_PAGE));
            $users = AuthService::listUsers($baseGroup, "", $offset, $USER_PER_PAGE);
            if($hashValue == 1){
                $groups = AuthService::listChildrenGroups($baseGroup);
            }else{
                $groups = array();
            }
        }else{
            $users = AuthService::listUsers($baseGroup);
            $groups = AuthService::listChildrenGroups($baseGroup);
        }
        foreach($groups as $groupId => $groupLabel){

            AJXP_XMLWriter::renderNode("/data/".$root."/".ltrim($groupId,"/"),
                $groupLabel, false, array(
                    "icon" => "users-folder.png",
                    "ajxp_mime" => "group"
                ));

        }
		$mess = ConfService::getMessages();
		$repos = ConfService::getRepositoriesList();
		$loggedUser = AuthService::getLoggedUser();		
        $userArray = array();
		foreach ($users as $userIndex => $userObject){
			$label = $userObject->getId();
			if($userObject->hasParent()){
				$label = $userObject->getParent()."000".$label;
			}
            $userArray[$label] = $userObject;
        }        
        ksort($userArray);
        foreach($userArray as $userObject) {
			$isAdmin = $userObject->isAdmin();
			$userId = $userObject->getId();
			$icon = "user".($userId=="guest"?"_guest":($isAdmin?"_admin":""));
			if($userObject->hasParent()){
				$icon = "user_child";
			}
			$rightsString = "";
			if($isAdmin) {
				$rightsString = $mess["ajxp_conf.63"];
			}else{
				$r = array();
				foreach ($repos as $repoId => $repository){
					if($repository->getAccessType() == "ajxp_shared") continue;
                    if(!$userObject->canRead($repoId) && !$userObject->canWrite($repoId)) continue;
                    $rs = ($userObject->canRead($repoId) ? "r" : "");
                    $rs .= ($userObject->canWrite($repoId) ? "w" : "");
                    $r[] = $repository->getDisplay()." (".$rs.")";
				}
				$rightsString = implode(", ", $r);
			}
            $nodeLabel = $userId;
            $test = $userObject->personalRole->filterParameterValue("core.conf", "USER_DISPLAY_NAME", AJXP_REPO_SCOPE_ALL, "");
            if(!empty($test)) $nodeLabel = $test;
            $scheme = AuthService::getAuthScheme($userId);
			AJXP_XMLWriter::renderNode("/users/".$userId, $nodeLabel, true, array(
				"isAdmin" => $mess[($isAdmin?"ajxp_conf.14":"ajxp_conf.15")], 
				"icon" => $icon.".png",
                "auth_scheme" => ($scheme != null? $scheme : ""),
				"rights_summary" => $rightsString,
				"ajxp_roles" => implode(", ", array_keys($userObject->getRoles())),
				"ajxp_mime" => "user".(($userId!="guest"&&$userId!=$loggedUser->getId())?"_editable":"")
			));
		}
	}
	
	function listRoles(){
		AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="ajxp_conf.roles">
			<column messageId="ajxp_conf.6" attributeName="ajxp_label" sortType="String"/>			
			<column messageId="ajxp_conf.114" attributeName="is_default" sortType="String"/>
			<column messageId="ajxp_conf.62" attributeName="rights_summary" sortType="String"/>
			</columns>');
		if(!AuthService::usersEnabled()) return ;
		$roles = AuthService::getRolesList(array(), !$this->listSpecialRoles);
		$mess = ConfService::getMessages();
		$repos = ConfService::getRepositoriesList();
        ksort($roles);
        foreach($roles as $roleId => $roleObject) {
            //if(strpos($roleId, "AJXP_GRP_") === 0 && !$this->listSpecialRoles) continue;
			$r = array();
            if(!AuthService::canAdministrate($roleObject)) continue;
			foreach ($repos as $repoId => $repository){
				if($repository->getAccessType() == "ajxp_shared") continue;
                if(!$roleObject->canRead($repoId) && !$roleObject->canWrite($repoId)) continue;
                $rs = ($roleObject->canRead($repoId) ? "r" : "");
                $rs .= ($roleObject->canWrite($repoId) ? "w" : "");
                $r[] = $repository->getDisplay()." (".$rs.")";
			}
			$rightsString = implode(", ", $r);
			AJXP_XMLWriter::renderNode("/roles/".$roleId, $roleId, true, array(
				"icon" => "user-acl.png",
				"rights_summary" => $rightsString,
                "is_default"    => implode(",", $roleObject->listAutoApplies()), //($roleObject->autoAppliesTo("standard") ? $mess[440]:$mess[441]),
				"ajxp_mime" => "role",
                "text"      => $roleObject->getLabel()
			));
		}
	}
	
    function repositoryExists($name)
    {
		$repos = ConfService::getRepositoriesList();
        foreach ($repos as $obj)
            if ($obj->getDisplay() == $name) return true;

        return false;
    }

	function listRepositories(){
		$repos = ConfService::getRepositoriesList();
		AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="ajxp_conf.repositories">
			<column messageId="ajxp_conf.8" attributeName="ajxp_label" sortType="String"/>
			<column messageId="ajxp_conf.9" attributeName="accessType" sortType="String"/>
			<column messageId="ajxp_shared.27" attributeName="owner" sortType="String"/>
			<column messageId="ajxp_conf.106" attributeName="repository_id" sortType="String"/>
			</columns>');		
        $repoArray = array();
        $childRepos = array();
        $templateRepos = array();
        $flatChildrenRepos = array();
		foreach ($repos as $repoIndex => $repoObject){
            if(!AuthService::canAdministrate($repoObject)){
                continue;
            }
			if($repoObject->getAccessType() == "ajxp_conf" || $repoObject->getAccessType() == "ajxp_shared") continue;
			if(is_numeric($repoIndex)) $repoIndex = "".$repoIndex;
            $name = AJXP_Utils::xmlEntities(SystemTextEncoding::toUTF8($repoObject->getDisplay()));
			if($repoObject->hasOwner() || $repoObject->hasParent()) {
				$parentId = $repoObject->getParentId();
                if(isSet($repos[$parentId]) && AuthService::canAdministrate($repos[$parentId])){
                    if(!isSet($childRepos[$parentId])) $childRepos[$parentId] = array();
                    $childRepos[$parentId][] = array("name" => $name, "index" => $repoIndex);
                    $flatChildrenRepos[] = $repoIndex;
                    continue;
                }
			}
			if($repoObject->isTemplate){
				$templateRepos[$name] = $repoIndex;
			}else{
	            $repoArray[$name] = $repoIndex;
			}
        }
        // Sort the list now by name
        ksort($templateRepos);        
        ksort($repoArray);
        $repoArray = array_merge($templateRepos, $repoArray);
        // Append child repositories
        $sortedArray = array();
        foreach ($repoArray as $name => $repoIndex) {
        	$sortedArray[$name] = $repoIndex;
        	if(isSet($childRepos[$repoIndex]) && is_array($childRepos[$repoIndex])){
        		foreach ($childRepos[$repoIndex] as $childData){
        			$sortedArray[$childData["name"]] = $childData["index"];
        		}
        	}
        }
        foreach ($sortedArray as $name => $repoIndex) {
            $repoObject =& $repos[$repoIndex];
            $icon = (in_array($repoIndex, $flatChildrenRepos)?"repo_child.png":"hdd_external_unmount.png");
            $editable = $repoObject->isWriteable();
            if($repoObject->isTemplate) {
                $icon = "hdd_external_mount.png";
                if(AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/"){
                    $editable = false;
                }
            }
            $metaData = array(
            	"repository_id" => $repoIndex,
            	"accessType"	=> ($repoObject->isTemplate?"Template for ":"").$repoObject->getAccessType(),
            	"icon"			=> $icon,
            	"owner"			=> ($repoObject->hasOwner()?$repoObject->getOwner():""),
            	"openicon"		=> $icon,
            	"parentname"	=> "/repositories",
				"ajxp_mime" 	=> "repository".($editable?"_editable":"")
            );
            AJXP_XMLWriter::renderNode("/repositories/$repoIndex", $name, true, $metaData);
		}
	}
	
	function listLogFiles($dir, $root = NULL){
        $dir = "/$dir";
		$logger = AJXP_Logger::getInstance();
		$parts = explode("/", $dir);
		if(count($parts)>4){
			$config = '<columns switchDisplayMode="list" switchGridMode="grid" template_name="ajxp_conf.logs">
				<column messageId="ajxp_conf.17" attributeName="date" sortType="MyDate" defaultWidth="10%"/>
				<column messageId="ajxp_conf.18" attributeName="ip" sortType="String" defaultWidth="10%"/>
				<column messageId="ajxp_conf.19" attributeName="level" sortType="String" defaultWidth="10%"/>
				<column messageId="ajxp_conf.20" attributeName="user" sortType="String" defaultWidth="10%"/>
				<column messageId="ajxp_conf.21" attributeName="action" sortType="String" defaultWidth="10%"/>
				<column messageId="ajxp_conf.22" attributeName="params" sortType="String" defaultWidth="50%"/>
			</columns>';				
			AJXP_XMLWriter::sendFilesListComponentConfig($config);
			$date = $parts[count($parts)-1];
			$logger->xmlLogs($dir, $date, "tree", $root."/logs");
		}else{
			AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="ajxp_conf.16" attributeName="ajxp_label" sortType="String"/></columns>');
			$logger->xmlListLogFiles("tree", (count($parts)>2?$parts[2]:null), (count($parts)>3?$parts[3]:null), $root."/logs");
		}
	}
	
	function printDiagnostic(){
		$outputArray = array();
		$testedParams = array();
		$passed = AJXP_Utils::runTests($outputArray, $testedParams);
		AJXP_Utils::testResultsToFile($outputArray, $testedParams);		
		AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="list" switchGridMode="fileList" template_name="ajxp_conf.diagnostic" defaultWidth="20%"><column messageId="ajxp_conf.23" attributeName="ajxp_label" sortType="String"/><column messageId="ajxp_conf.24" attributeName="data" sortType="String"/></columns>');		
		if(is_file(TESTS_RESULT_FILE)){
			include_once(TESTS_RESULT_FILE);
            if(isset($diagResults)){
                foreach ($diagResults as $id => $value){
                    $value = AJXP_Utils::xmlEntities($value);
                    print "<tree icon=\"susehelpcenter.png\" is_file=\"1\" filename=\"$id\" text=\"$id\" data=\"$value\" ajxp_mime=\"testResult\"/>";
                }
            }
		}
	}
	
	function listSharedFiles(){
		AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="ajxp_conf.shared">
				<column messageId="ajxp_shared.4" attributeName="ajxp_label" sortType="String" defaultWidth="30%"/>
				<column messageId="ajxp_shared.27" attributeName="owner" sortType="String" defaultWidth="10%"/>
				<column messageId="ajxp_shared.17" attributeName="download_url" sortType="String" defaultWidth="40%"/>
				<column messageId="ajxp_shared.6" attributeName="password" sortType="String" defaultWidth="4%"/>
				<column messageId="ajxp_shared.7" attributeName="expiration" sortType="String" defaultWidth="4%"/>
				<column messageId="ajxp_shared.20" attributeName="expired" sortType="String" defaultWidth="4%"/>
				<column messageId="ajxp_shared.14" attributeName="integrity" sortType="String" defaultWidth="4%" hidden="true"/>
			</columns>');
		$dlFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
		if(!is_dir($dlFolder)) return ;		
		$files = glob($dlFolder."/*.php");
		if($files === false) return ;
		$mess = ConfService::getMessages();
		$loggedUser = AuthService::getLoggedUser();
		$userId = $loggedUser->getId();
		$dlURL = ConfService::getCoreConf("PUBLIC_DOWNLOAD_URL");
        if($dlURL!= ""){
        	$downloadBase = rtrim($dlURL, "/");
        }else{
	        $fullUrl = AJXP_Utils::detectServerURL() . dirname($_SERVER['REQUEST_URI']);
	        $downloadBase = str_replace("\\", "/", $fullUrl.rtrim(str_replace(AJXP_INSTALL_PATH, "", $dlFolder), "/"));
        }
		
		foreach ($files as $file){
			$publicletData = $this->loadPublicletData($file);			
			AJXP_XMLWriter::renderNode(str_replace(".php", "", basename($file)), "".SystemTextEncoding::toUTF8($publicletData["REPOSITORY"]->getDisplay()).":/".SystemTextEncoding::toUTF8($publicletData["FILE_PATH"]), true, array(
				"icon"		=> "html.png",
				"password" => ($publicletData["PASSWORD"]!=""?$publicletData["PASSWORD"]:"-"), 
				"expiration" => ($publicletData["EXPIRE_TIME"]!=0?date($mess["date_format"], $publicletData["EXPIRE_TIME"]):"-"), 
				"expired" => ($publicletData["EXPIRE_TIME"]!=0?($publicletData["EXPIRE_TIME"]<time()?$mess["ajxp_shared.21"]:$mess["ajxp_shared.22"]):"-"), 
				"integrity"  => (!$publicletData["SECURITY_MODIFIED"]?$mess["ajxp_shared.15"]:$mess["ajxp_shared.16"]),
				"download_url" => $downloadBase . "/".basename($file),
				"owner" => (isset($publicletData["OWNER_ID"])?$publicletData["OWNER_ID"]:"-"),
				"ajxp_mime" => "shared_file")
			);			
		}
	}
	
	function metaSourceOrderingFunction($key1, $key2){
        $a1 = explode(".", $key1);
		$t1 = array_shift($a1);
        $a2 = explode(".", $key2);
		$t2 = array_shift($a2);
		if($t1 == "index") return 1;
        if($t1 == "metastore") return -1;
		if($t2 == "index") return -1;
        if($t2 == "metastore") return 1;
        if($key1 == "meta.git" || $key1 == "meta.svn") return 1;
        if($key2 == "meta.git" || $key2 == "meta.svn") return -1;
		return 0;
	}
	
	function clearExpiredFiles(){
		$files = glob(ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER")."/*.php");
		$loggedUser = AuthService::getLoggedUser();
		$userId = $loggedUser->getId();
		$deleted = array();
		foreach ($files as $file){
			$publicletData = $this->loadPublicletData($file);			
			if(isSet($publicletData["EXPIRATION_TIME"]) && is_numeric($publicletData["EXPIRATION_TIME"]) && $publicletData["EXPIRATION_TIME"] > 0 && $publicletData["EXPIRATION_TIME"] < time()){
				unlink($file);
				$deleted[] = basename($file);
			}
		}
		return $deleted;
	}
	
	protected function loadPublicletData($file){
        $inputData = null;
		$lines = file($file);
		$id = str_replace(".php", "", basename($file));
		$code = $lines[3] . $lines[4] . $lines[5];
		eval($code);
		$dataModified = (md5($inputData) != $id);
		$publicletData = unserialize($inputData);
		$publicletData["SECURITY_MODIFIED"] = $dataModified;		
		return $publicletData;
	}
		
	function updateUserRole($userId, $roleId, $addOrRemove, $updateSubUsers = false){
		$confStorage = ConfService::getConfStorageImpl();		
		$user = $confStorage->createUserObject($userId);
		//if($user->hasParent()) return $user;
		if($addOrRemove == "add"){
            $roleObject = AuthService::getRole($roleId);
			$user->addRole($roleObject);
		}else{
			$user->removeRole($roleId);
		}
		$user->save("superuser");
		$loggedUser = AuthService::getLoggedUser();
		if($loggedUser->getId() == $user->getId()){
			AuthService::updateUser($user);
		}
		return $user;
		
	}
	
	
	function parseParameters(&$repDef, &$options, $userId = null, $globalBinaries = false){

        AJXP_Utils::parseStandardFormParameters($repDef, $options, $userId, "DRIVER_OPTION_", ($globalBinaries?array():null));

	}

}