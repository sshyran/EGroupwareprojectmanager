<?php
/**************************************************************************\
* eGroupWare - ProjectManager - Milestones user interface                  *
* http://www.egroupware.org                                                *
* Written and (c) 2005 by Ralf Becker <RalfBecker@outdoor-training.de>     *
* --------------------------------------------                             *
*  This program is free software; you can redistribute it and/or modify it *
*  under the terms of the GNU General Public License as published by the   *
*  Free Software Foundation; either version 2 of the License, or (at your  *
*  option) any later version.                                              *
\**************************************************************************/

/* $Id$ */

include_once(EGW_INCLUDE_ROOT.'/projectmanager/inc/class.boprojectmanager.inc.php');

/**
 * Milestones user interface of the projectmanager
 *
 * @package projectmanager
 * @author RalfBecker-AT-outdoor-training.de
 * @copyright (c) 2005 by RalfBecker-AT-outdoor-training.de
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */
class uimilestones extends boprojectmanager 
{
	/**
	 * @var array $public_functions Functions to call via menuaction
	 */
	var $public_functions = array(
		'edit'  => true,
		'view'  => true,
	);
	var $tpl;
	
	/**
	 * Constructor, calls the constructor of the extended class
	 */
	function uimilestones()
	{
		$this->tpl =& CreateObject('etemplate.etemplate');

		if ((int) $_REQUEST['pm_id'])
		{
			$pm_id = (int) $_REQUEST['pm_id'];
		}
		else
		{
			$pm_id = $GLOBALS['egw']->session->appsession('pm_id','projectmanager');
		}
		if (!$pm_id)
		{
			$this->tpl->location(array(
				'menuaction' => 'projectmanager.uiprojectmanager.index',
				'msg'        => lang('You need to select a project first'),
			));
		}
		$this->boprojectmanager($pm_id);
		
		// check if we have at least read-access to this project
		if (!$this->check_acl(EGW_ACL_READ))
		{
			$this->tpl->location(array(
				'menuaction' => 'projectmanager.uiprojectmanager.index',
				'msg'        => lang('Permission denied !!!'),
			));
		}
		if (!is_object($this->milestones))
		{
			$this->milestones =& CreateObject('projectmanager.somilestones');
		}
	}
	
	/**
	 * View a milestone, only calls edit(null,true);
	 */
	function view()
	{
		$this->edit(null,true);
	}

	/**
	 * Edit a milestone
	 *
	 * @param array $content=null posted content
	 * @param boolean $view=false only view the milestone?
	 */
	function edit($content=null,$view=false)
	{
		$view = $view || $content['view'] || !$this->check_acl(EGW_ACL_EDIT);
		
		if (is_array($content))
		{
			if ($content['pm_id'] != $this->data['pm_id'])
			{
				$this->read($content['pm_id']);
			}
			$this->milestones->data_merge($content);

			if ($this->check_acl(EGW_ACL_EDIT))
			{
				if ($content['save'] || $content['apply'])
				{
					if ($this->milestones->save() != 0)
					{
						$msg = lang('Error: saving milestone');
						unset($content['save']);
					}
					else
					{
						$msg = lang('Milestone saved');
						$js = "opener.location.href='".$GLOBALS['phpgw']->link('/index.php',array(
							'menuaction' => 'projectmanager.ganttchart.show',
							'msg'        => $msg,
						))."';";
					}
				}
				if ($content['delete'] && $content['ms_id'])
				{
					if ($this->milestones->delete(array(
						'pm_id' => $content['pm_id'],
						'ms_id' => $content['ms_id'],
					)))
					{
						$msg = lang('Milestone deleted');
						$js = "opener.location.href='".$GLOBALS['phpgw']->link('/index.php',array(
							'menuaction' => 'projectmanager.ganttchart.show',
							'msg'        => $msg,
						))."';";
					}
				}
				if ($content['edit'] && $this->check_acl(EGW_ACL_EDIT))
				{
					$view = false;
				}
			}
			if ($content['save'] || $content['cancel'] || $content['delete'])
			{
				$js .= 'window.close();';
				echo '<html><body onload="'.$js.'"></body></html>';
				$GLOBALS['egw']->common->egw_exit();
			}
		}
		elseif ($_REQUEST['ms_id'])
		{
			$this->milestones->read(array(
				'ms_id' => $_REQUEST['ms_id'],
			));
		}
		else
		{
			$this->milestones->data['pm_id'] = $this->data['pm_id'];
		}
		$content = $this->milestones->data + array(
			'msg' => $msg,
			'js'  => '<script>'.$js.'</script>',
		);

		if ($view)
		{
			$readonlys = array(
				'edit'     => !$this->check_acl(EGW_ACL_EDIT),
				'save'     => true,
				'apply'    => true,
				'delete'   => true,
				'pm_id'    => true,
				'ms_title' => true,
				'ms_date'  => true,
				'ms_description' => true,
			);
			$sel_options = array(
				'pm_id' => array($this->data['pm_id'] => $this->data['pm_title']),
			);
		}
		else
		{
			$readonlys = array(
				'edit'   => true,
			);
			$sel_options = array(
				'pm_id' => $this->query_list('pm_title','pm_id'),
			);
		}
		$readonlys['delete'] = !$this->milestones->data['ms_id'] || !$this->check_acl(EGW_ACL_EDIT);

		$GLOBALS['egw_info']['flags']['app_header'] = lang('projectmanager').' - '.($view ? lang('View milestone') : 
			($this->milestones->data['ms_id'] ? lang('Edit milestone') : lang('Add milestone')));
		$this->tpl->read('projectmanager.milestone.edit');
		$this->tpl->exec('projectmanager.uimilestones.edit',$content,$sel_options,$readonlys,$this->milestones->data+array(
			'view'  => $view,
		),2);
	}	 
}