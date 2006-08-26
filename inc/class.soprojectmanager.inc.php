<?php
/**
 * ProjectManager - General (projects) storage object
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package projectmanager
 * @copyright (c) 2005 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$ 
 */

include_once(EGW_INCLUDE_ROOT.'/etemplate/inc/class.so_sql.inc.php');

/**
 * General storage object of the projectmanager: access the main project data
 *
 * Tables: egw_pm_projects, egw_pm_extra, egw_pm_roles, egw_pm_members
 *
 * A project P is the parent of an other project C, if link_id1=P.pm_id and link_id2=C.pm_id !
 */
class soprojectmanager extends so_sql
{
	/**
	 * Table name 'egw_links'
	 * 
	 * @var string
	 */
	var $links_table = 'egw_links';
	/**
	 * Configuration data
	 * 
	 * @var array
	 */
	var $config = array(
		'customfields' => array(),
	);
	/**
	 * Custom fields
	 *
	 * @var array
	 */
	var $customfields;
	/**
	 * Name of customefields table
	 * 
	 * @var string
	 */
	var $extra_table = 'egw_pm_extra';
	/**
	 * Name of project-members table
	 * 
	 * @var string
	 */
	var $members_table = 'egw_pm_members';
	/**
	 * Name of roles table
	 * 
	 * @var string
	 */
	var $roles_table = 'egw_pm_roles';
	/**
	 * Join with the members and the roles table to get the role-ACL of the current user
	 * 
	 * @var string
	 */
	var $acl_join;
	/**
	 * Extracolumns from the members table
	 * 
	 * @var array/string
	 */
	var $acl_extracols='role_acl';
	/**
	 * ACL grants from other users
	 * 
	 * @var array
	 */
	var $grants;
	var $read_grants,$private_grants;

	/**
	 * Constructor, calls the constructor of the extended class
	 * 
	 * @param int $pm_id id of the project to load, default null
	 * @return soprojectmanger
	 */
	function soprojectmanager($pm_id=null)
	{
		$this->so_sql('projectmanager','egw_pm_projects');
		
		$config =& CreateObject('phpgwapi.config','projectmanager');
		$config->read_repository();
		$this->config =& $config->config_data;
		unset($config);
		$this->customfields =& $this->config['customfields'];
		$this->config['duration_format'] = str_replace(',','',$this->config['duration_units']).','.$this->config['hours_per_workday'];

		$this->grants = $GLOBALS['egw']->acl->get_grants('projectmanager');
		$this->user = (int) $GLOBALS['egw_info']['user']['account_id'];

		$this->read_grants = $this->private_grants = array();
		foreach($this->grants as $owner => $rights)
		{
			if ($rights) $this->read_grants[] = $owner;		// ANY ACL implies READ!
			
			if ($rights & EGW_ACL_PRIVATE) $this->private_grants[] = $owner;
		}
		$this->acl_join = "LEFT JOIN $this->members_table ON ($this->table_name.pm_id=$this->members_table.pm_id AND member_uid=$this->user) ".
			" LEFT JOIN $this->roles_table ON $this->members_table.role_id=$this->roles_table.role_id";

		if ($pm_id) $this->read($pm_id);
	}
	
	/**
	 * reads a project
	 *
	 * reimplemented to handle custom fields
	 */
	function read($keys)
	{
		//echo "<p>soprojectmanager::read(".print_r($keys,true).")</p>\n";

		if ($keys && is_numeric($keys) && $this->data['pm_id'] == $keys ||
			$keys['pm_id'] &&  $this->data['pm_id'] == $keys['pm_id'])
		{
			return $this->data;
		}
		if (!parent::read($keys))
		{
			return false;
		}
		if ($this->customfields)
		{
			$this->db->select($this->extra_table,'*',array('pm_id' => $this->data['pm_id']),__LINE__,__FILE__);

			while (($row = $this->db->row(true)))
			{
				$this->data['#'.$row['pm_extra_name']] = $row['pm_extra_value'];
			}
		}
		// query project_members and their roles
		$this->db->select($this->members_table,'*',$this->members_table.'.pm_id='.(int)$this->data['pm_id'],__LINE__,__FILE__,
			False,'',False,0,"LEFT JOIN $this->roles_table ON $this->members_table.role_id=$this->roles_table.role_id");
	
		while (($row = $this->db->row(true)))
		{
			$this->data['pm_members'][$row['member_uid']] = $row;
		}
		$this->data['role_acl'] = $this->data['pm_members'][$this->user]['role_acl'];
		
		return $this->data;
	}
	
	/**
	 * saves a project
	 *
	 * reimplemented to handle custom fields and set modification and creation data
	 *
	 * @param array $keys if given $keys are copied to data before saveing => allows a save as
	 * @return int 0 on success and errno != 0 else
	 */
	function save($keys=null)
	{
		//echo "soprojectmanager::save(".print_r($keys,true).") this->data="; _debug_array($this->data);
		
		if (is_array($keys) && count($keys))
		{
			$this->data_merge($keys);
			$keys = null;
		}
		if (parent::save($keys) == 0 && $this->data['pm_id'])
		{
			if ($this->customfields)
			{
				// custome fields: first delete all, then save the ones with non-empty content
				$this->db->delete($this->extra_table,array('pm_id' => $this->data['pm_id']),__LINE__,__FILE__);
				foreach($this->customfields as $name => $data)
				{
					if ($name && isset($this->data['#'.$name]) && !empty($this->data['#'.$name]))
					{
						$this->db->insert($this->extra_table,array(
							'pm_id'          => $this->data['pm_id'],
							'pm_extra_name'  => $name,
							'pm_extra_value' => $this->data['#'.$name],
						),false,__LINE__,__FILE__);
					}
				}
			}
			// project-members: first delete all, then save the (still) assigned ones
			$this->db->delete($this->members_table,array('pm_id' => $this->data['pm_id']),__LINE__,__FILE__);
			foreach((array)$this->data['pm_members'] as $uid => $data)
			{
				$this->db->insert($this->members_table,array(
					'pm_id'      => $this->data['pm_id'],
					'member_uid' => $uid,
					'role_id'    => $data['role_id'],
					'member_availibility' => $data['member_availibility'], 
				),false,__LINE__,__FILE__);
			}
		}
		return $this->db->Errno;
	}
	
	/**
	 * merges in new values from the given new data-array
	 *
	 * reimplemented to also merge the customfields
	 *
	 * @param $new array in form col => new_value with values to set
	 */
	function data_merge($new)
	{
		parent::data_merge($new);
		
		if (is_array($this->customfields))
		{
			foreach($this->customfields as $name => $data)
			{
				if (isset($new['#'.$name]))
				{
					$this->data['#'.$name] = $new['#'.$name];
				}
			}
		}
	}

	/**
	 * search projects, re-implemented to include sub-cats and allow to filter for subs and mains
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
	 * @param string/boolean $join=true sql to do a join, added as is after the table-name, default true=add join for acl
	 * @return array of matching rows (the row is an array of the cols) or False
	 */
	function search($criteria,$only_keys=True,$order_by='',$extra_cols='',$wildcard='',$empty=False,$op='AND',$start=false,$filter=null,$join=true,$need_full_no_count=false)
	{
		// include sub-categories in the search
		if ($filter['cat_id'])
		{
			if (!is_object($GLOBALS['egw']->categories))
			{
				$GLOBALS['egw']->categories =& CreateObject('phpgwapi.categories');
			}
			$filter['cat_id'] = $GLOBALS['egw']->categories->return_all_children($filter['cat_id']);
		}
		if ($join === true)	// add acl-join, to get role_acl of current user
		{
			$join = $this->acl_join;
			
			if (!is_array($extra_cols)) $extra_cols = $extra_cols ? explode(',',$extra_cols) : array();
			$extra_cols = array_merge($extra_cols,array(
				$this->acl_extracols,
				$this->table_name.'.pm_id AS pm_id',
			));
			if ($only_keys === true) $only_keys='';	// otherwise we use ambigues pm_id
	
			if (isset($criteria['pm_id']))
			{
				$criteria[$this->table_name.'.pm_id'] = $criteria['pm_id'];
				unset($criteria['pm_id']);
			}
			if (isset($filter['pm_id']) && $filter['pm_id'])
			{
				$filter[$this->table_name.'.pm_id'] = $filter['pm_id'];
				unset($filter['pm_id']);
			}
			// include an ACL filter for read-access
			$filter[] = "(pm_access='anonym' OR pm_access='public' AND pm_creator IN (".implode(',',$this->read_grants).
				") OR pm_access='private' AND pm_creator IN (".implode(',',$this->private_grants).')'.
				($join == $this->acl_join ? ' OR '.$this->roles_table.'.role_acl!=0' : '').')';
		}
		if ($filter['subs_or_mains'])
		{
/* old code using a sub-query
			$ids = "SELECT link_id2 FROM $this->links_table WHERE link_app2='projectmanager' AND link_app1='projectmanager'";
			
			if (is_array($filter['subs_or_mains']))		// sub-projects of given parent-projects
			{
				$ids .= ' AND '.$this->db->expression($this->links_table,array('link_id1' => $filter['subs_or_mains']));
				$filter['subs_or_mains'] = 'subs';
			}
			if (!$this->db->capabilities['sub_queries'])
			{
				$this->db->query($ids,__LINE__,__FILE__);
				$ids = array();
				while($this->db->next_record())
				{
					$ids[] = $this->db->f(0);
				}
				$ids = count($ids) ? implode(',',$ids) : 0;
			}
			$filter[] = $this->table_name.'.pm_id '.($filter['subs_or_mains'] == 'mains' ? 'NOT ' : '').'IN ('.$ids.')';
*/
			// new code using a JOIN
			if ($filter['subs_or_mains'] == 'mains')
			{
				$filter[] = 'link_id2 IS NULL';
				$join .= ' LEFT';
			}
			$join .= " JOIN $this->links_table ON link_app2='projectmanager' AND link_app1='projectmanager' AND link_id2=$this->table_name.pm_id";

			if (is_array($filter['subs_or_mains']))	// sub-projects of given parent-projects
			{
				$join .= ' AND '.$this->db->expression($this->links_table,array('link_id1' => $filter['subs_or_mains']));
			}
		}
		unset($filter['subs_or_mains']);
		
		return parent::search($criteria,$only_keys,$order_by,$extra_cols,$wildcard,$empty,$op,$start,$filter,$join,$need_full_no_count);
	}
	
	/**
	 * reimplemented to set some defaults and cope with ambigues pm_id column
	 *
	 * @param string $value_col='pm_title' column-name for the values of the array, can also be an expression aliased with AS
	 * @param string $key_col='pm_id' column-name for the keys, default '' = same as $value_col: returns a distinct list
	 * @param array $filter=array() to filter the entries
	 * @return array with key_col => value_col pairs, ordered by value_col
	 */
	function query_list($value_col='pm_title',$key_col='pm_id',$filter=array('pm_status'=>'active'))
	{
		if ($key_col == 'pm_id') $key_col = $this->table_name.'.pm_id AS pm_id';
		
		return parent::query_list($value_col,$key_col,$filter);
	}
	
	/**
	 * read the general availebility of one or all users
	 *
	 * A not set availibility is by default 100%
	 *
	 * @param int $uid user-id or 0 to read all
	 * @return array uid => availibility
	 */
	function get_availibility($uid=0)
	{
		$where = array('pm_id' => 0);
		
		if ($uid) $where['member_uid'] = $uid;
		
		$this->db->select($this->members_table,'member_uid,member_availibility',$where,__LINE__,__FILE__);
		$avails = array();
		while (($row = $this->db->row(true)))
		{
			$avails[$row['member_uid']] = empty($row['member_availibility']) ? 100.0 : $row['member_availibility'];
		}
		return $avails;
	}
	
	/**
	 * set or delete the general availibility of a user
	 *
	 * A not set availibility is by default 100%
	 *
	 * @param int $uid user-id
	 * @param float $availiblity=null percentage to set or nothing to delete the avail. for the user
	 */
	function set_availibility($uid,$availibility=null)
	{
		if (!is_numeric($uid)) return;

		if (!is_null($availibility) && !empty($availibility) && $availibility != 100.0)
		{
			$this->db->insert($this->members_table,array(
				'member_availibility' => $availibility
			),array(
				'member_uid' => $uid,
				'pm_id'      => 0,
			),__LINE__,__FILE__);
		}
		else
		{
			$this->db->delete($this->members_table,array(
				'pm_id' => 0,
				'member_uid' => $uid,
			),__LINE__,__FILE__);
		}
	}
}