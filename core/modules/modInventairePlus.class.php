<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Fred S. Omega Junior
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   inventaireplus     Module InventairePlus
 *  \brief      InventairePlus module descriptor.
 *
 *  \file       htdocs/inventaireplus/core/modules/modInventairePlus.class.php
 *  \ingroup    inventaireplus
 *  \brief      Description and activation file for module InventairePlus
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module InventairePlus
 */
class modInventairePlus extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 501107; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'inventaireplus';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "Fred Omega Junior";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleInventairePlusName' not found (InventairePlus is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModuleInventairePlusDesc' not found (InventairePlus is name of module).
		$this->description = "Fonctionnalités transverses pour les inventaires Dolibarr";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "Fonctionnalités transverses pour les inventaires Dolibarr";

		// Author
		$this->editor_name = 'Fred Omega Junior';		// Author name
		$this->editor_url = 'www.linkedin.com/in/frédéric-h-887621160';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@inventaireplus'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where INVENTAIREPLUS is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'fa-balance-scale';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 1,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/inventaireplus/css/inventaireplus.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/inventaireplus/js/inventaireplus.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				'data' => array('leftblock', 'inventorycard', 'receptioncard', 'stocklist', 'stocklistInventairePlus', 'stockmovementlist', 'stockmovementlistInventairePlus', 'massstockmoveinventaireplus'),
				'entity' => '0',
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/inventaireplus/temp","/inventaireplus/subdir");
		$this->dirs = array("/inventaireplus/temp", "/inventaireplus/stock_cashier_sheet");

		// Config pages. Put here list of php page, stored into inventaireplus/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@inventaireplus");

		// Dependencies
		// A condition to hide module
		$this->hidden = getDolGlobalInt('MODULE_INVENTAIREPLUS_DISABLED'); // A condition to disable module;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("inventaireplus@inventaireplus");

		// Prerequisites
		$this->phpmin = array(7, 1); // Minimum version of PHP required by module
		// $this->phpmax = array(8, 0); // Maximum version of PHP required by module
		$this->need_dolibarr_version = array(19, -3); // Minimum version of Dolibarr required by module
		// $this->max_dolibarr_version = array(19, -3); // Maximum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array(); // Warning to show when we activate module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		//$this->automatic_activation = array('FR'=>'InventairePlusWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = true;								// If true, can't be disabled

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('INVENTAIREPLUS_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('INVENTAIREPLUS_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array(
			array('INVENTAIREPLUS_MAIN_WAREHOUSE_ID', 'chaine', '', 'Default source warehouse for mass stock transfers', 1, 'current', 1),
			array('INVENTAIREPLUS_STOCK_SHEET_WAREHOUSE_ID', 'chaine', '', 'Warehouse used for cashier stock sheets', 1, 'current', 1),
			array('INVENTAIREPLUS_STOCK_SHEET_ENABLED', 'chaine', '1', 'Enable cashier stock sheet generation', 1, 'current', 1),
			array('INVENTAIREPLUS_RESTRICT_STOCK_SHEET', 'chaine', '0', 'Restrict cashier stock sheet to moved products', 1, 'current', 1),
		);

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isModEnabled("inventaireplus")) {
			$conf->inventaireplus = new stdClass();
			$conf->inventaireplus->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		$this->tabs = array();
		/* END MODULEBUILDER TABS */
		// Example:
		// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data' => 'objecttype:+tabname1:Title1:mylangfile@inventaireplus:$user->hasRight(\'inventaireplus\', \'read\'):/inventaireplus/mynewtab1.php?id=__ID__');
		// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data' => 'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@inventaireplus:$user->hasRight(\'othermodule\', \'read\'):/inventaireplus/mynewtab2.php?id=__ID__',
		// To remove an existing tab identified by code tabname
		// $this->tabs[] = array('data' => 'objecttype:-tabname:NU:conditiontoremove');
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'delivery'         to add a tab in delivery view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'supplier_invoice' to add a tab in supplier invoice view
		// 'member'           to add a tab in foundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'supplier_order'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'supplier_payment' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view


		// Dictionaries
		/* Example:
		 $this->dictionaries=array(
		 'langs' => 'inventaireplus@inventaireplus',
		 // List of tables we want to see into dictionary editor
		 'tabname' => array("table1", "table2", "table3"),
		 // Label of tables
		 'tablib' => array("Table1", "Table2", "Table3"),
		 // Request to select fields
		 'tabsql' => array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table3 as f'),
		 // Sort order
		 'tabsqlsort' => array("label ASC", "label ASC", "label ASC"),
		 // List of fields (result of select to show dictionary)
		 'tabfield' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields to edit a record)
		 'tabfieldvalue' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields for insert)
		 'tabfieldinsert' => array("code,label", "code,label", "code,label"),
		 // Name of columns with primary key (try to always name it 'rowid')
		 'tabrowid' => array("rowid", "rowid", "rowid"),
		 // Condition to show each dictionary
		 'tabcond' => array(isModEnabled('inventaireplus'), isModEnabled('inventaireplus'), isModEnabled('inventaireplus')),
		 // Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
		 'tabhelp' => array(array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		 );
		 */
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		// Add here list of php file(s) stored in inventaireplus/core/boxes that contains a class to show a widget.
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'inventairepluswidget1.php@inventaireplus',
			//      'note' => 'Widget provided by InventairePlus',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			//  0 => array(
			//      'label' => 'MyJob label',
			//      'jobtype' => 'method',
			//      'class' => '/inventaireplus/class/myobject.class.php',
			//      'objectname' => 'MyObject',
			//      'method' => 'doScheduledJob',
			//      'parameters' => '',
			//      'comment' => 'Comment',
			//      'frequency' => 2,
			//      'unitfrequency' => 3600,
			//      'status' => 0,
			//      'test' => 'isModEnabled("inventaireplus")',
			//      'priority' => 50,
			//  ),
		);
		/* END MODULEBUILDER CRON */
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("inventaireplus")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("inventaireplus")', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Créer des transferts de stock en masse';
		$this->rights[$r][4] = 'massstockmove';
		$this->rights[$r][5] = 'create';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Créer des mouvements de stock depuis InventairePlus';
		$this->rights[$r][4] = 'stock';
		$this->rights[$r][5] = 'create';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Générer et imprimer la fiche de stock caissier';
		$this->rights[$r][4] = 'stock_cashier_sheet';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Créer les mouvements de transfert de stock à partir d\'une réception';
		$this->rights[$r][4] = 'transferreceptiontowarehouseinventaireplus';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Créer un inventaire sur une sélection libre de produits';
		$this->rights[$r][4] = 'custominventoryselection';
		$this->rights[$r][5] = 'create';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Utiliser les traitements optimisés pour inventaires volumineux';
		$this->rights[$r][4] = 'largeinventory';
		$this->rights[$r][5] = 'write';
		$r++;
		/* BEGIN MODULEBUILDER PERMISSIONS */
		/*
		$o = 1;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Read objects of InventairePlus'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'read'; // In php code, permission will be checked by test if ($user->hasRight('inventaireplus', 'myobject', 'read'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 2); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Create/Update objects of InventairePlus'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'write'; // In php code, permission will be checked by test if ($user->hasRight('inventaireplus', 'myobject', 'write'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 3); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Delete objects of InventairePlus'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'delete'; // In php code, permission will be checked by test if ($user->hasRight('inventaireplus', 'myobject', 'delete'))
		$r++;
		*/
		/* END MODULEBUILDER PERMISSIONS */


		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'WarehouseList',
			'mainmenu' => 'products',
			'leftmenu' => 'inventaireplus_stock_list',
			'url' => '/inventaireplus/product/stock/list.php',
			'langs' => 'inventaireplus@inventaireplus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")',
			'perms' => '$user->rights->stock->lire',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'MassStockTransfer',
			'mainmenu' => 'products',
			'leftmenu' => 'inventaireplus_massstockmove',
			'url' => '/inventaireplus/product/stock/massstockmove.php',
			'langs' => 'inventaireplus@inventaireplus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")',
			'perms' => '$user->hasRight("inventaireplus", "massstockmove", "create")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'StockAtDateVal',
			'mainmenu' => 'products',
			'leftmenu' => 'inventaireplus_stockatdate',
			'url' => '/inventaireplus/product/stock/stockatdate.php',
			'langs' => 'inventaireplus@inventaireplus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")',
			'perms' => '$user->rights->stock->lire',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'StockCashierSheet',
			'mainmenu' => 'products',
			'leftmenu' => 'inventaireplus_stockcashiersheet',
			'url' => '/inventaireplus/product/stock/stockcashiersheet.php',
			'langs' => 'inventaireplus@inventaireplus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")',
			'perms' => '$user->hasRight("inventaireplus", "stock_cashier_sheet", "read")',
			'target' => '',
			'user' => 0,
		);

		/* 		$this->menu[$r++] = array(
			'fk_menu' => '', // Will be stored into mainmenu + leftmenu. Use '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'top', // This is a Top menu entry
			'titre' => 'ModuleInventairePlusName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'inventaireplus',
			'leftmenu' => '',
			'url' => '/inventaireplus/inventaireplusindex.php',
			'langs' => 'inventaireplus@inventaireplus', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")', // Define condition to show or hide menu entry. Use 'isModEnabled("inventaireplus")' if entry must be visible if module is enabled.
			'perms' => '1', // Use 'perms'=>'$user->hasRight("inventaireplus", "myobject", "read")' if you want your menu with a permission rules
			'target' => '',
			'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
		); */
		/* END MODULEBUILDER TOPMENU */

		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */
		/*
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=inventaireplus',      // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',                          // This is a Left menu entry
			'titre' => 'MyObject',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'inventaireplus',
			'leftmenu' => 'myobject',
			'url' => '/inventaireplus/inventaireplusindex.php',
			'langs' => 'inventaireplus@inventaireplus',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")', // Define condition to show or hide menu entry. Use 'isModEnabled("inventaireplus")' if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("inventaireplus", "myobject", "read")',
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=inventaireplus,fk_leftmenu=myobject',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'New_MyObject',
			'mainmenu' => 'inventaireplus',
			'leftmenu' => 'inventaireplus_myobject_new',
			'url' => '/inventaireplus/myobject_card.php?action=create',
			'langs' => 'inventaireplus@inventaireplus',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")', // Define condition to show or hide menu entry. Use 'isModEnabled("inventaireplus")' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms' => '$user->hasRight("inventaireplus", "myobject", "write")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=inventaireplus,fk_leftmenu=myobject',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'List_MyObject',
			'mainmenu' => 'inventaireplus',
			'leftmenu' => 'inventaireplus_myobject_list',
			'url' => '/inventaireplus/myobject_list.php',
			'langs' => 'inventaireplus@inventaireplus',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("inventaireplus")', // Define condition to show or hide menu entry. Use 'isModEnabled("inventaireplus")' if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("inventaireplus", "myobject", "read")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		*/
		/* END MODULEBUILDER LEFTMENU MYOBJECT */


		// Exports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/*
		$langs->load("inventaireplus@inventaireplus");
		$this->export_code[$r] = $this->rights_class.'_'.$r;
		$this->export_label[$r] = 'MyObjectLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r] = $this->picto;
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'MyObject'; $keyforclassfile='/inventaireplus/class/myobject.class.php'; $keyforelement='myobject@inventaireplus';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'MyObjectLine'; $keyforclassfile='/inventaireplus/class/myobject.class.php'; $keyforelement='myobjectline@inventaireplus'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='myobject'; $keyforaliasextra='extra'; $keyforelement='myobject@inventaireplus';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='myobjectline'; $keyforaliasextra='extraline'; $keyforelement='myobjectline@inventaireplus';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('myobjectline' => array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field' => '...');
		//$this->export_examplevalues_array[$r] = array('t.field' => 'Example');
		//$this->export_help_array[$r] = array('t.field' => 'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.$this->db->prefix().'inventaireplus_myobject as t';
		//$this->export_sql_end[$r]  .=' LEFT JOIN '.$this->db->prefix().'inventaireplus_myobject_line as tl ON tl.fk_myobject = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('myobject').')';
		$r++; */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("inventaireplus@inventaireplus");
		$this->import_code[$r] = $this->rights_class.'_'.$r;
		$this->import_label[$r] = 'MyObjectLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r] = $this->picto;
		$this->import_tables_array[$r] = array('t' => $this->db->prefix().'inventaireplus_myobject', 'extra' => $this->db->prefix().'inventaireplus_myobject_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'MyObject'; $keyforclassfile='/inventaireplus/class/myobject.class.php'; $keyforelement='myobject@inventaireplus';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='myobject'; $keyforaliasextra='extra'; $keyforelement='myobject@inventaireplus';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.$this->db->prefix().'inventaireplus_myobject');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('INVENTAIREPLUS_MYOBJECT_ADDON') ? 'mod_myobject_standard' : getDolGlobalString('INVENTAIREPLUS_MYOBJECT_ADDON')),
				'path'=>"/core/modules/inventaireplus/".(!getDolGlobalString('INVENTAIREPLUS_MYOBJECT_ADDON') ? 'mod_myobject_standard' : getDolGlobalString('INVENTAIREPLUS_MYOBJECT_ADDON')).'.php',
				'classobject'=>'MyObject',
				'pathobject'=>'/inventaireplus/class/myobject.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		//$result = $this->_load_tables('/install/mysql/', 'inventaireplus');
		$result = $this->_load_tables('/inventaireplus/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result0=$extrafields->addExtraField('inventaireplus_separator1', "Separator 1", 'separator', 1,  0, 'thirdparty',   0, 0, '', array('options'=>array(1=>1)), 1, '', 1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');
		//$result1=$extrafields->addExtraField('inventaireplus_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', -1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');
		//$result2=$extrafields->addExtraField('inventaireplus_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', -1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');
		//$result3=$extrafields->addExtraField('inventaireplus_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', -1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');
		//$result4=$extrafields->addExtraField('inventaireplus_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', -1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');
		//$result5=$extrafields->addExtraField('inventaireplus_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', -1, 0, '', '', 'inventaireplus@inventaireplus', 'isModEnabled("inventaireplus")');

		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$inventoryPlusExtraFields = array(
			array('countsheet_generated_at', "Date génération fiche de décompte", 'datetime', '', 'inventory', 'Date de génération de la fiche de décompte produits'),
			array('countsheet_file', "Fichier fiche de décompte", 'varchar', '255', 'inventory', 'Chemin du dernier PDF fiche de décompte généré'),
			array('discrepancy_pdf_generated_at', "Date génération point des écarts", 'datetime', '', 'inventory', 'Date de génération du point des écarts'),
			array('discrepancy_pdf_file', "Fichier point des écarts", 'varchar', '255', 'inventory', 'Chemin du dernier PDF point des écarts généré'),
			array('pv_generated_at', "Date génération PV inventaire", 'datetime', '', 'inventory', "Date de génération du procès-verbal d'inventaire"),
			array('pv_file', "Fichier PV inventaire", 'varchar', '255', 'inventory', "Chemin du dernier PDF procès-verbal d'inventaire généré"),
			array('product_ref_snapshot', "Réf produit snapshot inventaire", 'varchar', '128', 'inventorydet', "Référence produit figée pour les documents d'inventaire"),
			array('product_label_snapshot', "Désignation snapshot inventaire", 'varchar', '255', 'inventorydet', "Désignation produit figée pour les documents d'inventaire"),
			array('category_label_snapshot', "Catégorie snapshot inventaire", 'varchar', '255', 'inventorydet', "Libellé de catégorie figé pour les documents d'inventaire"),
			array('batch_snapshot', "Lot snapshot inventaire", 'varchar', '128', 'inventorydet', "Lot figé pour les documents d'inventaire"),
			array('eatby_snapshot', "Date péremption courte snapshot", 'datetime', '', 'inventorydet', "Date eatby figée pour les documents d'inventaire"),
			array('sellby_snapshot', "Date péremption snapshot", 'datetime', '', 'inventorydet', "Date sellby figée pour les documents d'inventaire"),
			array('justification_text', "Justification écart inventaire", 'text', '', 'inventorydet', "Justification manuelle de l'écart d'inventaire"),
		);
		foreach ($inventoryPlusExtraFields as $inventoryPlusExtraField) {
			$extrafields->addExtraField($inventoryPlusExtraField[0], $inventoryPlusExtraField[1], $inventoryPlusExtraField[2], 100, $inventoryPlusExtraField[3], $inventoryPlusExtraField[4], 0, 0, '', array('options' => array('' => null)), 0, '', '0', $inventoryPlusExtraField[5], '', '', 'inventaireplus@inventaireplus', '$conf->inventaireplus->enabled');
			$extrafields->updateExtraField($inventoryPlusExtraField[0], $inventoryPlusExtraField[1], $inventoryPlusExtraField[2], 100, $inventoryPlusExtraField[3], $inventoryPlusExtraField[4], 0, 0, '', array('options' => array('' => null)), 0, '', '0', $inventoryPlusExtraField[5], '', '', 'inventaireplus@inventaireplus', '$conf->inventaireplus->enabled');
		}
		$stockMovementTransferExtraFields = array(
			array('transfer_source', "Entrepôt source transfert", 'int', '', 'Entrepôt source figé pour le bordereau de transfert'),
			array('transfer_target', "Entrepôt cible transfert", 'int', '', 'Entrepôt de destination figé pour le bordereau de transfert'),
			array('transfer_category_id', "Catégorie transfert", 'int', '', 'Identifiant de catégorie figé pour le bordereau de transfert'),
			array('transfer_category_label', "Libellé catégorie transfert", 'varchar', '255', 'Libellé de catégorie figé pour le bordereau de transfert'),
			array('transfer_category_rank', "Rang catégorie transfert", 'int', '', "Ordre d'affichage de la catégorie sur le bordereau de transfert"),
			array('transfer_pdf_file', "Fichier PDF transfert", 'varchar', '255', 'Chemin du dernier bordereau PDF généré pour ce transfert'),
			array('transfer_pdf_generated_at', "Date génération PDF transfert", 'datetime', '', 'Date de génération du dernier bordereau PDF de transfert'),
			array('transfer_origin_type', "Type origine transfert", 'varchar', '32', "Type de l'objet source du transfert"),
			array('transfer_origin_id', "Origine réception transfert", 'sellist', '', 'Origine réception du transfert'),
		);
		foreach ($stockMovementTransferExtraFields as $stockMovementTransferExtraField) {
			$extraFieldOptions = ($stockMovementTransferExtraField[0] === 'transfer_origin_id') ? array('options' => array('reception:ref:rowid' => null)) : array('options' => array('' => null));
			$extrafields->addExtraField($stockMovementTransferExtraField[0], $stockMovementTransferExtraField[1], $stockMovementTransferExtraField[2], 100, $stockMovementTransferExtraField[3], 'stock_mouvement', 0, 0, '', $extraFieldOptions, 0, '', '0', $stockMovementTransferExtraField[4], '', '', 'inventaireplus@inventaireplus', '$conf->inventaireplus->enabled');
			$extrafields->updateExtraField($stockMovementTransferExtraField[0], $stockMovementTransferExtraField[1], $stockMovementTransferExtraField[2], 100, $stockMovementTransferExtraField[3], 'stock_mouvement', 0, 0, '', $extraFieldOptions, 0, '', '0', $stockMovementTransferExtraField[4], '', '', 'inventaireplus@inventaireplus', '$conf->inventaireplus->enabled');
		}
		// Permissions
		$this->remove($options);

		$sql = array();

		// Document templates
		$moduledir = dol_sanitizeFileName('inventaireplus');
		$myTmpObjects = array();
		$myTmpObjects['MyObject'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);

		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_myobjects.odt';
				$dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
				$dest = $dirodt.'/template_myobjects.odt';

				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, '0', 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}

				$sql = array_merge($sql, array(
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'standard_".strtolower($myTmpObjectKey)."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('standard_".strtolower($myTmpObjectKey)."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'generic_".strtolower($myTmpObjectKey)."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('generic_".strtolower($myTmpObjectKey)."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
				));
			}
		}

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
