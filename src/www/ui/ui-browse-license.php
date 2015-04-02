<?php
/***********************************************************
 * Copyright (C) 2008-2015 Hewlett-Packard Development Company, L.P.
 *               2014-2015 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\BusinessRules\ClearingDecisionFilter;
use Fossology\Lib\BusinessRules\LicenseMap;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Plugin\DefaultPlugin;
use Fossology\Lib\Proxy\ScanJobProxy;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * \file ui-browse-license.php
 * \brief browse a directory to display all licenses in this directory
 */

class ui_browse_license extends DefaultPlugin
{
  const NAME = "license";
  
  private $uploadtree_tablename = "";
  /** @var UploadDao */
  private $uploadDao;
  /** @var LicenseDao */
  private $licenseDao;
  /** @var ClearingDao */
  private $clearingDao;
  /** @var AgentDao */
  private $agentDao;
  /** @var ClearingDecisionFilter */
  private $clearingFilter;
  /** @var LicenseMap */
  private $licenseProjector;
  /** @var array */
  protected $agentNames = array('nomos' => 'N', 'monk' => 'M', 'ninka' => 'Nk');
  
  public function __construct() {
    parent::__construct(self::NAME, array(
        self::TITLE => _("License Browser"),
        self::DEPENDENCIES => array("browse", "view"),
        self::PERMISSION => Auth::PERM_READ
    ));

    global $container;
    $this->uploadDao = $container->get('dao.upload');
    $this->licenseDao = $container->get('dao.license');
    $this->clearingDao = $container->get('dao.clearing');
    $this->agentDao = $container->get('dao.agent');
    $this->clearingFilter = $container->get('businessrules.clearing_decision_filter');
  }

  /**
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array("upload", "item"));

    $Item = GetParm("item", PARM_INTEGER);
    $Upload = GetParm("upload", PARM_INTEGER);
    if (empty($Item) || empty($Upload))
      return;
    $viewLicenseURI = "view-license" . Traceback_parm_keep(array("show", "format", "page", "upload", "item"));
    $menuName = $this->Title;
    if (GetParm("mod", PARM_STRING) == self::NAME)
    {
      menu_insert("Browse::$menuName", 100);
    }
    else
    {
      $text = _("license histogram");
      menu_insert("Browse::$menuName", 100, $URI, $text);
      menu_insert("View::$menuName", 100, $viewLicenseURI, $text);
    }
  }

  /**
   * @param Request $request
   * @return Response
   */
  protected function handle(Request $request) {
    $upload = intval($request->get("upload"));
    $groupId = Auth::getGroupId();
    if (!$this->uploadDao->isAccessible($upload, $groupId)) {
      return $this->flushContent(_("Permission Denied"));
    }

    $item = intval($request->get("item"));

    $vars['baseuri'] = Traceback_uri();
    $vars['uploadId'] = $upload;
    $vars['itemId'] = $item;

    $this->uploadtree_tablename = $this->uploadDao->getUploadtreeTableName($upload);
    $vars['micromenu'] = Dir2Browse($this->Name, $item, NULL, $showBox = 0, "Browse", -1, '', '', $this->uploadtree_tablename);
    $vars['licenseArray'] = $this->licenseDao->getLicenseArray();


    $itemTreeBounds = $this->uploadDao->getItemTreeBounds($item, $this->uploadtree_tablename);
    $left = $itemTreeBounds->getLeft();
    if (empty($left))
    {
      return $this->flushContent(_("Job unpack/adj2nest hasn't completed."));
    }
    $histVars = $this->showUploadHist($itemTreeBounds);
    if(is_a($histVars, 'Symfony\\Component\\HttpFoundation\\RedirectResponse'))
    {
      return $histVars;
    }
    $vars = array_merge($vars, $histVars);

    $vars['content'] = js_url();

    return $this->render("browse.html.twig",$this->mergeWithDefault($vars));
  }


  /**
   * \brief Given an $Uploadtree_pk, display:
   *   - The histogram for the directory BY LICENSE.
   *   - The file listing for the directory.
   */
  private function showUploadHist(ItemTreeBounds $itemTreeBounds)
  {
    $groupId = Auth::getGroupId();
    $selectedAgentId = GetParm('agentId', PARM_INTEGER);
    $tag_pk = GetParm("tag", PARM_INTEGER);

    $uploadId = $itemTreeBounds->getUploadId();
    $scannerAgents = array_keys($this->agentNames);
    $scanJobProxy = new ScanJobProxy($this->agentDao, $uploadId);
    $scannerVars = $scanJobProxy->createAgentStatus($scannerAgents);
    $agentMap = $scanJobProxy->getAgentMap();
    
    $vars = array('agentId' => GetParm('agentId', PARM_INTEGER),
                  'agentShowURI' => Traceback_uri() . '?mod=' . Traceback_parm(),
                  'agentMap' => $agentMap,
                  'scanners'=>$scannerVars);

    $selectedAgentIds = empty($selectedAgentId) ? $scanJobProxy->getLatestSuccessfulAgentIds() : $selectedAgentId;
    
    if(!empty($agentMap))
    {
      $licVars = $this->createLicenseHistogram($itemTreeBounds->getItemId(), $tag_pk, $itemTreeBounds, $selectedAgentIds, $groupId);
      $vars = array_merge($vars, $licVars);
    }

    $this->licenseProjector = new LicenseMap($this->getObject('db.manager'),$groupId,LicenseMap::CONCLUSION,true);
    $dirVars = $this->countFileListing($itemTreeBounds);
    $childCount = $dirVars['iTotalRecords'];
    /***************************************
     * Problem: $ChildCount can be zero if you have a container that does not
     * unpack to a directory.  For example:
     * file.gz extracts to archive.txt that contains a license.
     * Same problem seen with .pdf and .Z files.
     * Solution: if $ChildCount == 0, then just view the license!
     *
     * $ChildCount can also be zero if the directory is empty.
     * **************************************/
    if ($childCount == 0)
    {
      return new RedirectResponse("?mod=view-license" . Traceback_parm_keep(array("upload", "item")));
    }

    $vars['licenseUri'] = Traceback_uri() . "?mod=popup-license&rf=";
    $vars['bulkUri'] = Traceback_uri() . "?mod=popup-license";

    $vars = array_merge($vars, $dirVars);
    return $vars;
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @return array with keys 'isFlat','iTotalRecords','fileSwitch'
   */
  private function countFileListing(ItemTreeBounds $itemTreeBounds)
  {
    $isFlat = isset($_GET['flatten']);
    $vars['isFlat'] = $isFlat;
    $vars['iTotalRecords'] = count($this->uploadDao->getNonArtifactDescendants($itemTreeBounds, $isFlat));
    $uri = Traceback_uri().'?mod='.$this->Name.Traceback_parm_keep(array('upload','folder','show','item'));
    $vars['fileSwitch'] = $isFlat ? $uri : $uri."&flatten=yes";
    return $vars;
  }


  /**
   * @param $uploadTreeId
   * @param $tagId
   * @param ItemTreeBounds $itemTreeBounds
   * @param int|int[] $agentIds
   * @param ClearingDecision []
   * @return array
   */
  private function createLicenseHistogram($uploadTreeId, $tagId, ItemTreeBounds $itemTreeBounds, $agentIds, $groupId)
  {
    $fileCount = $this->uploadDao->countPlainFiles($itemTreeBounds);
    $licenseHistogram = $this->licenseDao->getLicenseHistogram($itemTreeBounds, $agentIds);
    $editedLicensesHist = $this->clearingDao->getClearedLicenseMultiplicities($itemTreeBounds, $groupId);

    $agentId = GetParm('agentId', PARM_INTEGER);
    $licListUri = Traceback_uri()."?mod=license_list_files&item=$uploadTreeId";
    if ($tagId)
    {
      $licListUri .= "&tag=$tagId";
    }
    if ($agentId)
    {
      $licListUri .= "&agentId=$agentId";
    }
    
    /* Write license histogram to $VLic  */
    list($tableData, $totalScannerLicenseCount, $editedTotalLicenseCount)
        = $this->createLicenseHistogramJSarray($licenseHistogram, $editedLicensesHist, $licListUri);
    
    $uniqueLicenseCount = count($tableData);
    $scannerUniqueLicenseCount = count( $licenseHistogram );
    $editedUniqueLicenseCount = count($editedLicensesHist);
    $noScannerLicenseFoundCount = array_key_exists("No_license_found", $licenseHistogram) ? $licenseHistogram["No_license_found"]['count'] : 0;
    $editedNoLicenseFoundCount = array_key_exists("No_license_found", $editedLicensesHist) ? $editedLicensesHist["No_license_found"]['count'] : 0;

    $vars = array('tableDataJson'=>json_encode($tableData),
        'uniqueLicenseCount'=>$uniqueLicenseCount,
        'fileCount'=>$fileCount,
        'scannerUniqueLicenseCount'=>$scannerUniqueLicenseCount,
        'editedUniqueLicenseCount'=>$editedUniqueLicenseCount,
        'scannerLicenseCount'=> $totalScannerLicenseCount-$noScannerLicenseFoundCount,
        'editedLicenseCount'=> $editedTotalLicenseCount-$editedNoLicenseFoundCount,
        'noScannerLicenseFoundCount'=>$noScannerLicenseFoundCount,
        'editedNoLicenseFoundCount'=>$editedNoLicenseFoundCount);

    return $vars;
  }

  /**
   * @param array $scannerLics
   * @param array $editedLics
   * @param string
   * @return array
   * @todo convert to template
   */
  protected function createLicenseHistogramJSarray($scannerLics, $editedLics, $licListUri)
  {
    $allScannerLicenseNames = array_keys($scannerLics);
    $allEditedLicenseNames = array_keys($editedLics);

    $allLicNames = array_unique(array_merge($allScannerLicenseNames, $allEditedLicenseNames));

    $totalScannerLicenseCount = 0;
    $editedTotalLicenseCount = 0;

    $tableData = array();
    foreach ($allLicNames as $licenseShortName)
    {
      $count = 0;
      if (array_key_exists($licenseShortName, $scannerLics))
      {
        $count = $scannerLics[$licenseShortName]['unique'];
      }
      $editedCount = array_key_exists($licenseShortName, $editedLics) ? $editedLics[$licenseShortName] : 0;

      $totalScannerLicenseCount += $count;
      $editedTotalLicenseCount += $editedCount;

      $scannerCountLink = ($count > 0) ? "<a href='$licListUri&lic=" . urlencode($licenseShortName) . "'>$count</a>": "0";
      $editedLink = ($editedCount > 0) ? $editedCount : "0";

      $tableData[] = array($scannerCountLink, $editedLink, $licenseShortName);
    }

    return array($tableData, $totalScannerLicenseCount, $editedTotalLicenseCount);
  }

  /**
   * @param string $templateName
   * @param array $vars
   * @return string
   */
  public function renderString($templateName, $vars)
  {
    return $this->renderer->loadTemplate($templateName)->render($vars);
  }  
}

register_plugin(new ui_browse_license());
