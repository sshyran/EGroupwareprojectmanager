<?php
/**
 * ProjectManager - Elements business object
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package projectmanager
 * @copyright (c) 2005-8 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Egw;
use EGroupware\Api\Acl;

/**
 * Elements business object of the projectmanager
 */
class projectmanager_elements_bo extends projectmanager_elements_so
{
	/**
	 * Debuglevel: 0 = no debug-messages, 1 = main, 2 = more, 3 = all, 4 = all incl. Api\Storage\Base, or string with function-name to debug
	 *
	 * @var int/string
	 */
	const DEBUG = false;
	/**
	 * Instance of the projectmanager_bo-class
	 *
	 * @var projectmanager_bo
	 */
	var $project;
	/**
	 * Summary information of the current project
	 *
	 * @var array
	 */
	var $project_summary;
	/**
	 * Instance of the soconstraints-class
	 *
	 * @var soconstraints
	 */
	var $constraints;
	/**
	 * Instance of the somilestones-class
	 *
	 * @var somilestones
	 */
	var $milestones;
	/**
	 * Instance of the soeroles-class
	 *
	 * @var soeroles
	 */
	var $eroles;
	/**
	 * List of applications currently supported by eroles
	 *
	 * @var erole_apps
	 */
	var $erole_apps = array('addressbook','calendar','infolog');
	/**
	 * Instances of the different datasources
	 *
	 * @var array
	 */
	var $datasources = array();
	/**
	 * Timestaps that need to be adjusted to user-time on reading or saving
	 *
	 * @var array
	 */
	var $timestamps = array(
		'pe_synced','pe_modified','pe_planned_start','pe_real_start','pe_planned_end','pe_real_end',
	);
	/**
	 * Offset in seconds between user and server-time,	it need to be add to a server-time to get the user-time
	 * or substracted from a user-time to get the server-time
	 *
	 * @var int
	 */
	var $tz_offset_s;
	/**
	 * Current time as timestamp in user-time
	 *
	 * @var int
	 */
	var $now_su;
	/**
	 * Translates filter-values to allowed stati
	 *
	 * @var array
	 */
	var $status_filter = array(
		'all'     => false,
		'used'    => '!ignore',
		'new'     => 'new',
		'ignored' => 'ignore',
	);
	/**
	 * User preferences
	 *
	 * @var array
	 */
	var $prefs;
	/**
	 * Or'ed id's of the values set by the last call to the updated method
	 *
	 * @var int
	 */
	var $updated = 0;

	/**
	 * Constructor, class the constructor of the extended class
	 *
	 * @param int $pm_id pm_id of the project to use, default null
	 * @param int $pe_id pe_id of the project-element to load, default null
	 */
	function __construct($pm_id=null,$pe_id=null)
	{
		$this->tz_offset_s = $GLOBALS['egw']->datetime->tz_offset;
		$this->now_su = Api\DateTime::server2user('now','ts');

		parent::__construct($pm_id,$pe_id);

		$this->project = new projectmanager_bo($pm_id);
		$this->config =& $this->project->config;
		$this->prefs = & $this->project->prefs;

		$this->project->instanciate('constraints,milestones');
		$this->constraints =& $this->project->constraints;
		$this->milestones  =& $this->project->milestones;
		$this->eroles	   = new projectmanager_eroles_bo($pm_id);

		$this->project_summary = $this->summary();

		if ((int)static::DEBUG >= 3 || static::DEBUG == 'projectmanager_elements_bo')
		{
			projectmanager_bo::debug_message(function_backtrace()."\nprojectmanager_elements_bo::projectmanager_elements_bo($pm_id,$pe_id) data=".print_r($this->data,true));
		}
		// save us in $GLOBALS['projectmanager_elements_bo'] for ExecMethod used in hooks
		if (!is_object($GLOBALS['projectmanager_elements_bo']))
		{
			$GLOBALS['projectmanager_elements_bo'] =& $this;
		}
	}

	/**
	 * receives notifications from the link-class: new, deleted links to pm entries, or updated content of linked entries
	 *
	 * We only process link- & update-notifications to parent-projects!
	 * A project P is the parent of an other project C, if link_id1=P.pm_id and link_id2=C.pm_id !
	 *
	 * @param array $data array with keys type, id, target_app, target_id, link_id, data
	 */
	public static function notify($data)
	{
		if ((int) static::DEBUG >= 2 || static::DEBUG == 'notify') projectmanager_bo::debug_message("projectmanager_elements_bo::notify(link_id=$data[link_id], type=$data[type], target=$data[target_app]-$data[target_id])");
		//error_log(__METHOD__."(".array2string($data).')');
		switch($data['type'])
		{
			case 'link':
			case 'update':
				// for projectmanager we need to check the direction of the link
				if ($data['target_app'] == 'projectmanager')
				{
					$link = Link::get_link($data['link_id']);
					if ($link['link_id2'] == $data['id'])
					{
						//error_log(__METHOD__."() --> ignoring notification to child");
						return;	// this is a notification to a child / subproject --> ignore it
					}
					// for new links we need to make sure the new child is not an ancestor of us
					if ($data['type'] == 'link')
					{
						if (($ancestors = projectmanager_bo::ancestors($data['id'])) && in_array($data['target_id'],$ancestors))
						{
							if ((int) static::DEBUG >= 2 || static::DEBUG == 'notify') projectmanager_bo::debug_message("projectmanager_elements_bo::notify: cant use pm_id=$data[target_id] as child as it's one of our (pm_id=$data[id]) ancestors=".print_r($ancestors,true));
							//error_log(__METHOD__."() --> ignoring notification as no project-element");
							return;	// the link is not used as an project-element, thought it's still a regular link
						}
						if ((int) static::DEBUG >= 3 || static::DEBUG == 'notify') projectmanager_bo::debug_message("projectmanager_elements_bo::notify: ancestors($data[id])=".print_r($ancestors,true));
					}
				}
				$e_bo = new projectmanager_elements_bo();
				// add pre-selected eroles to update request if app is supported
				if($e_bo->config['enable_eroles']
					&& in_array($data['target_app'],$e_bo->erole_apps)
					&& isset($_POST['exec']['nm']['eroles_add'])
				)
				{
					$extra_keys = array('pe_eroles' => implode(',',$_POST['exec']['nm']['eroles_add']));
				}
				$update_data = $e_bo->updateElement($data['target_app'],$data['target_id'],$data['link_id'],
					$data['id'],true,(isset($extra_keys) ? $extra_keys : null));
				break;

			case 'unlink':
				// Link class caches the object it notifies, so new object to avoid problems
				// (eg. delete happens on 2 different projects, or unlink a deleted project)
				$e_bo = new projectmanager_elements_bo();
				if($e_bo->project->history)
				{
					// Might be keeping this...
					$link = Link::get_link($data['link_id']);
					if($link['deleted'])
					{
						// Need to just delete, without changing links
						$e_bo->delete(array('pm_id' => $data['id'], 'pe_id' => $data['link_id']), false, false);
						return;
					}
				}
				$e_bo->delete(array('pm_id' => $data['id'],'pe_id' => $data['link_id']));
				break;

		}

		if($update_data && $update_data['pe_id'])
		{
				// Something changed with an entry.  Trigger update in place to update times.
				Api\Hooks::process([
						'location' => 'notify-all',
						'type'     => 'update-in-place',
						'app'      => 'projectelement',
						'id'       => $update_data['pe_id'],
						'data'     => $update_data,
				], null, true);
		}
	}

	/**
	 * Updates / creates a project-element with the data of it's datasource
	 *
	 * Sets additionally $this->updated with the or'ed id's of the updated values
	 *
	 * ToDo: if end-date changed, update elements which have "us" as start-constrain
	 *
	 * @param string $app appname
	 * @param string $id id of $app as used by the link-class and the datasource
	 * @param int $pe_id =0 element- / link-id or 0 to only read and return the entry, but not save it!
	 * @param int $pm_id =null project-id, default $this->pm_id
	 * @param boolean $update_project =true update the data in the project if necessary
	 * @param array $extra_keys =null key=>value pairs with element extra data to merge on update
	 * @return array/boolean the updated project-element or false on error (eg. no read access)
	 */
	function updateElement($app, $id, $pe_id=0, $pm_id=null, $update_project=true, $extra_keys=null)
	{
		if (!$pm_id) $pm_id = $this->pm_id;

		if ((int) static::DEBUG >= 2 || static::DEBUG == 'update') projectmanager_bo::debug_message("projectmanager_elements_bo::update(app='$app',id='$id',pe_id=$pe_id,pm_id=$pm_id)");
		//error_log(__METHOD__."('$app', $id, pe_id=$pe_id, pm_id=$pm_id, update_project=$update_project, extra_keys=".array2string($extra_keys).")");

		// Prevent infinite looping in some nested cases
		static $updated = array();
		if($updated["$app:$id:$pe_id"]) return;
		$updated["$app:$id:$pe_id"] = true;

		if (!$app || !$id || !(int) $pm_id)
		{
			return false;
		}
		$this->init();
		$need_save_anyway = false;
		$datasource =& $this->datasource($app);

		// check if entry already exists and set basic values if not
		if (!$pe_id || ($need_save_anyway = !$this->read(array('pm_id'=>$pm_id,'pe_id'=>$pe_id))))
		{
			$this->data = $datasource->read($id);
			$this->data['pm_id'] = $pm_id;
			$this->data['pe_id'] = $pe_id;
			$this->data['pe_overwrite'] = 0;		// none set so far
			$this->data['pe_status']= 'new';

			// if user linking has no ADD rights, the entry is set to ignored
			if (!$this->check_acl(Acl::ADD,array('pm_id'=>$pm_id)) && !
				($this->check_acl(EGW_ACL_ADD_TIMESHEET, array('pm_id'=>$pm_id)) && $app == 'timesheet')
			)
			{
				$this->data['pe_status']= 'ignore';
			}
		}
		if(!empty($extra_keys) && is_array($extra_keys))
		{
			$this->data_merge($extra_keys);
		}
		$this->updated = 0;

		// mask out not overwritable parts like title and details
		// in case they somehow get set (mayby by a previous bug)
		$this->data['pe_overwrite'] &= ~(PM_TITLE|PM_DETAILS);

		if (!($data = $datasource->read($id,$this->data)))
		{
			//error_log(__METHOD__."() --> no read access");
			return false;	// eg. no read access, so I cant update
		}
		foreach($data as $name => $value)
		{
			if (isset($datasource->name2id[$name]) && !($this->data['pe_overwrite'] & $datasource->name2id[$name]) &&
				// treat new entries / $need_save_anyway as changed
				($need_save_anyway || $this->data[$name] != $value))
			{
				//if ((int) $pe_id) error_log(__METHOD__."($app,$id,$pe_id,$pm_id) $name ({$datasource->name2id[$name]}) updated, pe_overwrite={$this->data['pe_overwrite']}: '{$this->data[$name]}' != '$value'");
				$this->data[$name] = $value;
				$this->updated |= $datasource->name2id[$name];
			}
		}
		$this->data['pe_synced'] = $this->now_su;

		if((int) $pe_id && ($need_save_anyway || $this->updated))
		{
			//error_log(__METHOD__."() pe_id=$pe_id, need_save_anyway=$need_save_anyway, this->updated=$this->updated --> saving");
			$this->save(null,false,$update_project ? $this->updated & ~PM_TITLE & ~PM_DETAILS & ~PM_RESOURCES : 0);	// dont set modified, only synced
		}
		return $this->data;
	}

	/**
	 * Update category in one or more project-elements
	 *
	 * @param int|array $pe_ids
	 * @param int $cat_id
	 * @return boolean|int false on error (no pm_id set or no rights) or number of changed elements
	 */
	function update_cat($pe_ids, $cat_id)
	{
		if (!$this->check_acl(Acl::EDIT, array(
			'pm_id' => $this->pm_id,
			'pe_id' => $pe_ids,
		)))
		{
			$ret = false;
		}
		else
		{
			$ret = parent::update_cat($pe_ids, $cat_id);
		}
		//error_log(__METHOD__."(".array2string($pe_ids).", $cat_id) pm_id=$this->pm_id returning ".array2string($ret));
		return $ret;
	}
	/**
	 * sync all project-elements
	 *
	 * The sync of the elements is done by calling the update-method for each (not ignored) element
	 * in the order of their planned starts and after that calling the projects update methode only
	 * once if necessary!
	 *
	 * @param int $pm_id=null id of project to use, default null=use $this->pm_id
	 * @return int number of updated elements
	 */
	function &sync_all($pm_id=null)
	{
		if (!is_array($GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited']))
		{
			$GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited'] = array();
		}
		if (!$pm_id && !($pm_id = $this->pm_id)) return 0;

		if ((int) static::DEBUG >= 2 || static::DEBUG == 'sync_all') projectmanager_bo::debug_message("projectmanager_elements_bo::sync_all(pm_id=$pm_id)");

		if ($GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited'][$pm_id])	// project already visited
		{
			if ((int) static::DEBUG >= 2 || static::DEBUG == 'sync_all') projectmanager_bo::debug_message("projectmanager_elements_bo::sync_all(pm_id=$pm_id) stoped recursion, as pm_id in (".implode(',',array_keys($GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited'])).")");
			return 0;							// no further recursion, might lead to an infinit loop
		}
		$GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited'][$pm_id] = true;

		$save_project = $this->project->data;

		$updated = $update_project = 0;
		++$GLOBALS['egw_info']['flags']['projectmanager']['pm_ds_ignore_elements'];
		foreach((array) $this->search(array('pm_id'=>$pm_id,"pe_status != 'ignore'"),false,'pe_planned_start') as $data)
		{
			$this->updateElement($data['pe_app'],$data['pe_app_id'],$data['pe_id'],$pm_id,false);

			$update_project |= $this->updated & ~PM_TITLE;
			if ($this->updated) $updated++;
		}
		--$GLOBALS['egw_info']['flags']['projectmanager']['pm_ds_ignore_elements'];
		if ($update_project)
		{
			$this->project->update($pm_id,$update_project);
		}
		if ($this->project->data['pm_id'] != $save_project['pm_id']) $this->project->data =& $save_project;

		unset($GLOBALS['egw_info']['flags']['projectmanager']['sync_all_pm_id_visited'][$pm_id]);

		return $updated;
	}

	/**
	 * checks if the user has enough rights for a certain operation
	 *
	 * The rights on a project-element depend on the rigths on the parent-project:
	 *	- One can only read an element, if he can read the project (any rights, at least READ on the project)
	 *	- Adding, editing and deleting of elements require the ADD right of the project (deleting requires the element to exist pe_id!=0)
	 *	- reading or editing of budgets require the concerned rights of the project
	 *
	 * @param int $required Acl::READ, ACL::EDIT, Acl::ADD, Acl::DELETE, EGW_ACL_BUDGET or EGW_ACL_EDIT_BUDGET
	 * @param array/int $data=null project-element or pe_id to use, default the project-element in $this->data
	 * @return boolean true if the rights are ok, false if not
	 */
	function check_acl($required,$data=0)
	{
		$pe_id = is_array($data) ? $data['pe_id'] : ($data ? $data : $this->data['pe_id']);
		$pm_id = is_array($data) ? $data['pm_id'] : ($data ? 0 : $this->data['pm_id']);

		if (!$pe_id && (!$pm_id || $required == Acl::DELETE))
		{
			return false;
		}
		if (!$pm_id)
		{
			$data_backup =& $this->data; unset($this->data);
			$data =& $this->read($pe_id);
			$this->data =& $data_backup; unset($data_backup);

			if (!$data) return false;	// not found ==> no rights

			$pm_id = $data['pm_id'];
		}
		if ($required == Acl::EDIT ||$required ==  Acl::DELETE)
		{
			$required = Acl::ADD;	// edit or delete of elements is handled by the ADD right of the project
		}
		return $this->project->check_acl($required,$pm_id);
	}

	/**
	 * Get reference to instance of the datasource used for $app
	 *
	 * The class has to be named datasource_$app and is search first in the App's inc-dir and then in the one of
	 * ProjectManager. If it's not found PM's datasource baseclass is used.
	 *
	 * @param string $app appname
	 * @return object
	 */
	function &datasource($app)
	{
		if (!isset($this->datasources[$app]))
		{
			if (!class_exists($class = $app.'_datasource'))		// if datasource can NOT be autoloaded --> try include the old names
			{
				if (!file_exists($classfile = EGW_INCLUDE_ROOT.'/'.$app.'/inc/class.'.($class='datasource_'.$app).'.inc.php') &&
					!file_exists($classfile = EGW_INCLUDE_ROOT.'/projectmanager/inc/class.'.($class='datasource_'.$app).'.inc.php'))
				{
					$classfile = EGW_INCLUDE_ROOT.'/projectmanager/inc/class.'.($class='datasource').'.inc.php';
				}
				include_once($classfile);
			}
			$this->datasources[$app] = new $class($app);
			// make the project available for the datasource
			$this->datasources[$app]->project =& $this->project;
		}
		return $this->datasources[$app];
	}

	/**
	 * changes the data from the db-format to your work-format
	 *
	 * reimplemented to adjust the timezone of the timestamps (adding $this->tz_offset_s to get user-time)
	 * Please note, we do NOT call the method of the parent or Api\Storage\Base !!!
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function db2data($data=null)
	{
		if (!is_array($data))
		{
			$data = &$this->data;
		}
		foreach($this->timestamps as $name)
		{
			if (isset($data[$name]) && $data[$name]) $data[$name] += $this->tz_offset_s;
		}
		if (is_numeric($data['pe_completion'])) $data['pe_completion'] .= '%';
		if ($data['pe_app']) $data['pe_icon'] = $data['pe_app'].'/navbar';
		if ($data['pe_resources']) $data['pe_resources'] = explode(',',$data['pe_resources']);

		return $data;
	}

	/**
	 * changes the data from your work-format to the db-format
	 *
	 * reimplemented to adjust the timezone of the timestamps (subtraction $this->tz_offset_s to get server-time)
	 * Please note, we do NOT call the method of the parent or Api\Storage\Base !!!
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function data2db($data=null)
	{
		if ($intern = !is_array($data))
		{
			$data = &$this->data;
		}
		foreach($this->timestamps as $name)
		{
			if (isset($data[$name]))
			{
				if ($data[$name])
				{
					$data[$name] -= $this->tz_offset_s;
				}
				else
				{
					$data[$name] = null;	// so it's not used for min or max dates
				}
			}
		}
		if (substr($data['pe_completion'],-1) == '%') $data['pe_completion'] = (int) substr($data['pe_completion'],0,-1);

		if (is_array($data['pe_resources']))
		{
			$data['pe_resources'] = count($data['pe_resources']) ? implode(',',$data['pe_resources']) : null;
		}
		return $data;
	}

	/**
	 * saves an project-element, reimplemented from SO, to save the remark in the link, if $keys['update_remark']
	 *
	 * @param array $keys=null if given $keys are copied to data before saveing => allows a save as
	 * @param boolean $touch_modified=true should modification date+user be set, default yes
	 * @param int $update_project=-1 update the data in the project (or'ed PM_ id's), default -1=everything
	 * @return int 0 on success and errno != 0 else
	 */
	function save($keys=null,$touch_modified=true,$update_project=-1)
	{
		if ((int) static::DEBUG >= 1 || static::DEBUG == 'save') projectmanager_bo::debug_message("projectmanager_elements_bo::save(".print_r($keys,true).','.(int)$touch_modified.",$update_project) data=".print_r($this->data,true));

		if ($keys['update_remark'] || $this->data['update_remark'])
		{
			unset($keys['update_remark']);
			unset($this->data['update_remark']);
			Link::update_remark($this->data['pe_id'],$this->data['pe_remark']);
		}
		if ($keys) $this->data_merge($keys);

		if ($touch_modified || !$this->data['pe_modified'] || !$this->data['pe_modifier'])
		{
			$this->data['pe_modified'] = $this->now_su;
			$this->data['pe_modifier'] = $GLOBALS['egw_info']['user']['account_id'];
		}
		if (!$this->data['pm_id']) $this->data['pm_id'] = $this->pm_id;

		if (!($err = parent::save()))
		{
			if (is_array($this->data['pe_constraints']))
			{
				$this->constraints->save(array(
					'pm_id' => $this->data['pm_id'],
					'pe_id' => $this->data['pe_id'],
				) + $this->data['pe_constraints']);
			}
			if ($update_project)
			{
				$this->project->update($this->data['pm_id'],$update_project,$this->data);
			}
		}
		return $err;
	}

	/**
	 * deletes a project-element or all project-elements of a project, reimplemented to remove the link too
	 *
	 * @param array/int $keys if given array with pm_id and/or pe_id or just an integer pe_id
	 * @param boolean $delete_sources =false true=delete datasources of the elements too (if supported by the datasource), false dont do it
	 * @param boolean $unlink =true Internal use only, passing false will skip the unlinking steps
	 * @return int affected rows, should be 1 if ok, 0 if an error
	 */
	function delete($keys=null,$delete_sources=false, $unlink=true)
	{
		if ((int) static::DEBUG >= 1 || static::DEBUG === 'delete') {
			projectmanager_bo::debug_message("projectmanager_elements_bo::delete(" . print_r($keys, true) . ",$delete_sources) this->data[pm_id] = " . $this->data['pm_id']);
		}

		if (!is_array($keys) && (int) $keys)
		{
			$keys = array('pe_id' => (int) $keys);
		}
		if (!is_null($keys))
		{
			$pm_id = $keys['pm_id'];
			$pe_id = $keys['pe_id'];
		}
		else
		{
			$pe_id = $this->data['pe_id'];
			$pm_id = $this->data['pm_id'];
		}
		// If project was just deleted but kept, its status will now be deleted
		if($this->project->history && $pm_id)
		{
			$project = $this->project->read($pm_id);
		}
		if ($delete_sources)
		{
			$this->run_on_sources('delete',$keys);
		}
		if($project && $project['pm_status'] == projectmanager_bo::DELETED_STATUS)
		{
			$ret = 1;
		}
		else
		{
			$ret = parent::delete($keys);
		}

		if ($pe_id)
		{
			if($unlink)
			{
				// delete one link
				Link::unlink($pe_id,'','',0,'','',(boolean)$this->project->history);
			}
			// update the project
			$this->project->update($pm_id);

			$this->constraints->delete(array('pe_id' => $pe_id));
		}
		elseif ($pm_id && $unlink)
		{
			// delete all links to project $pm_id
			Link::unlink(0,'projectmanager',$pm_id, '','!file','',(boolean)$this->project->history);
		}
		return $ret;
	}

	/**
	 * reads row matched by key and puts all cols in the data array, reimplemented to also read the constraints
	 *
	 * @param array $keys array with keys in form internalName => value, may be a scalar value if only one key
	 * @param string/array $extra_cols string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $join='' sql to do a join, added as is after the table-name, eg. ", table2 WHERE x=y" or
	 * @return array/boolean data if row could be retrived else False
	*/
	function read($keys,$extra_cols='',$join=true)
	{
		if (!($data = parent::read($keys,$extra_cols,$join)))
		{
			return false;
		}
		$this->data['pe_constraints'] = $this->constraints->read(array(
																	 'pm_id' => $this->data['pm_id'],
																	 'pe_id' => $this->data['pe_id'],
																 ));
		return $this->data;
	}

	function title($id)
	{
		return $this->titles([$id])[$id] ?: false;
	}

	/**
	 * reads the titles of all project-elements specified by $keys
	 *
	 * @param array $keys keys of elements to read, default empty = all of the project the class is instanciated for
	 * @return array with pe_id => lang(pe_app): pe_title pairs
	 */
	function &titles($keys = array())
	{
		$titles = array();

		// Support link titles, which just provides IDs
		if(!$keys['pe_id'] && !$keys['pm_id'] && !$keys['ms_id'] && count($keys))
		{
			$keys = array('pe_id' => $keys);
		}
		foreach((array) $this->search(array(),'pe_id,pe_title','pe_app,pe_title','','',false,'AND',false,$keys) as $element)
		{
			if ($element) $titles[$element['pe_id']] = lang($element['pe_app']).': '.$element['pe_title'];
		}
		return $titles;
	}

	/**
	 * query projectmanager elements for entries matching $pattern
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param string $pattern pattern to search
	 * @param array $options Array of options for the search
	 * @return array with pm_id - title pairs of the matching entries
	 */
	function link_query( $pattern, Array &$options = array() )
	{
		$limit = false;
		$need_count = false;
		if($options['start'] || $options['num_rows']) {
			$limit = array($options['start'], $options['num_rows']);
			$need_count = true;
		}
		$result = array();
		$filter = array();
		if($options['pm_id'])
		{
			$filter['pm_id'] = $options['pm_id'];
		}
		foreach((array) $this->search($pattern,false,'pm_id,pe_title','','%',false,'OR',$limit,$filter, true, $need_count) as $entry )
		{
			if ($entry['pe_id']) $result[$entry['pe_id']] = lang($entry['pe_app']).': '.$entry['pe_title'];
		}
		$options['total'] = $need_count ? $this->total : count($result);
		return $result;
	}

	/**
	 * Copies the elementtree from an other project
	 *
	 * This is done by calling the copy method of the datasource (if existent) and then calling update with the (new) app_id
	 *
	 * @param int $source
	 * @return array with old => new pe_id's on success
	 */
	function copytree($source)
	{
		if ((int) static::DEBUG >= 2 || static::DEBUG == 'copytree') projectmanager_bo::debug_message("projectmanager_elements_bo::copytree($source) this->pm_id=$this->pm_id");

		$elements =& $this->search('',false,'pe_planned_start,pe_title','','',false,'AND',false,array('pm_id'=>$source));
		if (!$elements) return array();

		// Calculate the difference in times between original project and new project
		// we can apply that difference to elements too.
		$offsets = $this->get_time_offsets($source, $this->pm_id);

		$copied = $apps_copied = $callbacks = $params = array();
		foreach($elements as $element)
		{
			$ds =& $this->datasource($element['pe_app']);

			if (method_exists($ds,'copy'))
			{
				if ((int) static::DEBUG >= 3 || static::DEBUG == 'copytree') projectmanager_bo::debug_message("copying $element[pe_app]:$element[pe_app_id] $element[pe_title]");
				$callback = $param = null;
				list($app_id,$link_id,$callback,$param) = $ds->copy($element,$this->pm_id,$this->project->data, $offsets);
				if (!is_null($callback))
				{
					$callbacks[] = $callback;
					$params[]    = $param;
				}
			}
			else	// no copy method, we just link again with that entry
			{
				if ((int) static::DEBUG >= 3 || static::DEBUG == 'copytree') projectmanager_bo::debug_message("linking $element[pe_app]:$element[pe_app_id] $element[pe_title]");
				$app_id = $element['pe_app_id'];
				$link_id = Link::link('projectmanager',$this->pm_id,$element['pe_app'],$app_id,$element['pe_remark'],0,0,1);
			}
			if ((int) static::DEBUG >= 3 || static::DEBUG == 'copytree') projectmanager_bo::debug_message("calling update($element[pe_app],$app_id,$link_id,$this->pm_id,false);");

			if (!$app_id || !$link_id) continue;	// something went wrong, eg. element no longer exists

			$this->updateElement($element['pe_app'],$app_id,$link_id,$this->pm_id,false);	// false=no update of project itself => done once at the end

			// copy evtl. overwriten content from the element
			if (($need_save = $element['pe_overwrite'] != 0))
			{
				foreach($ds->name2id as $name => $id)
				{
					if ($element['pe_overwrite'] & $id)
					{
						$this->data[$name] = $element[$name];
					}
				}
				$this->data['pe_overwrite'] = $element['pe_overwrite'];
			}
			// copy other element data
			foreach(array('pl_id','pe_cost_per_time','cat_id','pe_share','pe_status') as $name)
			{
				if ($name == 'pe_status' && $element['pe_status'] != 'ignore') continue;	// only copy ignored

				if ($this->data[$name] != $element[$name])
				{
					$this->data[$name] = $element[$name];
					$need_save = true;
				}
			}
			if ($need_save) $this->save(null,true,false);

			$copied[$element['pe_id']] = $link_id;
			$apps_copied[$element['pe_app']][$element['pe_app_id']] = $app_id;
		}
		// if datasources specifed a callback, call it after all copying with array translating old to new id's
		foreach($callbacks as $n => $callback)
		{
			call_user_func($callback,$params[$n],$apps_copied,$copied);
		}
		// now we do one update of our project
		if ((int) static::DEBUG >= 3 || static::DEBUG == 'copytree') projectmanager_bo::debug_message("calling project->update() this->pm_id=$this->pm_id");
		$this->project->update();

		return $copied;
	}

	/**
	 * Determine the time offsets between two projects
	 *
	 * We'll use the time offsets when copying templates to update the elements
	 *
	 * @param int $source Source project ID
	 * @param int $target Target project ID
	 *
	 * @return DateInterval[] Array of DateIntervals, indexed by field name
	 */
	protected function get_time_offsets($source, $target)
	{
		$offsets = array('planned_start'=>0,'planned_end'=>0,'real_start'=>0,'real_end'=>0);
		$offset_count = 0;

		if($this->project->data && $target == $this->project->data['pm_id'])
		{
			$target_project = $this->project->data;
			$source_project = $this->project->read($source);
			$this->project->data = $target_project;
		}
		else
		{
			$source_project = $this->project->read($source);
			$target_project = $this->project->read($target);
		}

		foreach($offsets as $date_field => &$offset)
		{
			if($source_project["pm_$date_field"] && $target_project["pm_$date_field"])
			{
				$source_date = new Api\DateTime($source_project["pm_$date_field"]);
				$target_date = new Api\DateTime($target_project["pm_$date_field"]);

				$offset = $source_date->diff($target_date);
				$offset_count++;
				//error_log("$source => $target $date_field " . $source_date . ' => ' . $target_date . ' = ' . (method_exists($offset,'format') ? $offset->format('%R%a days') : $offset));
			}
		}

		// Now set any that are missing using the "next-best" information - real
		// if planned is missing, end if start is missing
		if($offset_count && $offset_count != count($offsets))
		{
			$offsets['planned_start'] = $offsets['planned_start'] ?: $offsets['real_start'] ?: $offsets['planned_end'] ?: $offsets['real_end'];
			$offsets['planned_end'] = $offsets['planned_end'] ?: $offsets['real_end'] ?: $offsets['planned_start'] ?: $offsets['real_start'];
			$offsets['real_start'] = $offsets['real_start'] ?: $offsets['planned_start'] ?: $offsets['real_end'] ?: $offsets['planned_end'];
			$offsets['real_end'] = $offsets['real_end'] ?: $offsets['planned_end'] ?: $offsets['real_start'] ?: $offsets['planned_start'];
		}
		return $offsets;
	}

	/**
	 * Runs a certain method on the datasources of given elements
	 *
	 * @param string $method datasource method to call
	 * @param array $keys to specifiy the elements
	 * @param mixed $args=null 2. argument, after the pe_app_id
	 * @return boolean true on success, false otherwise
	 */
	function run_on_sources($method,$keys,$args=null)
	{
		if ((int) static::DEBUG >= 2 || static::DEBUG == 'run_on_sources') projectmanager_bo::debug_message("projectmanager_elements_bo::run_on_sources($method,".print_r($keys,true).','.print_r($args,true).") this->pm_id=$this->pm_id");

		$elements =& $this->search($keys,array('pe_id','pe_title'),'pe_planned_start');
		if (!$elements) return true;

		$Ok = true;
		foreach($elements as $element)
		{
			$ds =& $this->datasource($element['pe_app']);

			if (method_exists($ds,$method))
			{
				if ((int) static::DEBUG >= 3 || static::DEBUG == 'run_on_sources') projectmanager_bo::debug_message("calling $method for $element[pe_app]:$element[pe_app_id] $element[pe_title]");
				if (!$ds->$method($element['pe_app_id'],$args)) $Ok = false;
			}
		}
		return $Ok;
	}

	/**
	 * Search elements
	 *
	 * Reimplemented to cumulate eg. timesheets in also included infologs, if $filter['cumulate'] is true.
	 *
	 * @param array/string $criteria array of key and data cols, OR a SQL query (content for WHERE), fully quoted (!)
	 * @param boolean $only_keys True returns only keys, False returns all cols
	 * @param string $order_by fieldnames + {ASC|DESC} separated by colons ','
	 * @param string/array $extra_cols string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $wildcard appended befor and after each criteria
	 * @param boolean $empty False=empty criteria are ignored in query, True=empty have to be empty in row
	 * @param string $op defaults to 'AND', can be set to 'OR' too, then criteria's are OR'ed together
	 * @param int/boolean $start if != false, return only maxmatch rows begining with start
	 * @param array $filter if set (!=null) col-data pairs, to be and-ed (!) into the query without wildcards
	 * @param string/boolean $join=true default join with links-table or string as in Api\Storage\Base
	 * @return array of matching rows (the row is an array of the cols) or False
	 */
	function &search($criteria,$only_keys=True,$order_by='',$extra_cols='',$wildcard='',$empty=False,$op='AND',$start=false,$filter=null,$join=true,$need_full_no_count=false)
	{
		if ($this->pm_id && (!isset($filter['pm_id']) || !$filter['pm_id']))
		{
			$filter['pm_id'] = $this->pm_id;
		}
		if ($filter['cumulate'])
		{
			$cumulate = array();
			foreach((array)Api\Hooks::process(array(
				'location' => 'pm_cumulate',
				'pm_id' => $filter['pm_id'],
			)) as $app => $data)
			{
				if (is_array($data)) $cumulate += $data;
			}
			if ($cumulate)
			{
				//echo "<p align=right>cumulate-filter: ".$this->db->expression($this->table_name,'NOT ',array('pe_id' => array_keys($cumulate)))."</p>\n";
				$filter[] = $this->db->expression($this->table_name,'NOT (',array('pe_id' => array_keys($cumulate)),')');
			}
		}
		$rows = parent::search($criteria,$only_keys,$order_by,$extra_cols,$wildcard,$empty,$op,$start,$filter,$join,$need_full_no_count);

		if ($rows && $cumulate)
		{
			// get the pe_id of all returned rows
			$row_pe_ids = array();
			foreach($rows as $k => $row)
			{
				$row_pe_ids[$k] = $row['pe_id'];
			}
			// get pe_id's of to cumulate entries which are in $rows
			$cumulate_in = array();
			foreach($cumulate as $pe_id => $data)
			{
				if (in_array($data['other_id'],$row_pe_ids))
				{
					$cumulate_in[$pe_id] = $data['other_id'];
				}
			}
			if ($cumulate_in)	// do we have something (timesheets) to cumulate
			{
				foreach(parent::search(
						array('pe_id' => array_keys($cumulate_in))
						,false,'','','',False, 'AND',false,array(
							 'pe_status != "ignore"'
						)
				) as $to_cumulate)
				{
					// get the row, where the entry cumulates
					if (($k = array_search($cumulate_in[$to_cumulate['pe_id']],$row_pe_ids)) !== false)
					{
						//echo "kumulated in ".$rows[$k]['pe_title']; _debug_array($rows);
						foreach(array('pe_planned_time','pe_used_time','pe_planned_budget','pe_used_budget') as $name)
						{
							if ($to_cumulate[$name]) $rows[$k][$name] += $to_cumulate[$name];
						}
						//echo "-->"; _debug_array($rows);
					}
				}
			}
		}
		return $rows;
	}}
