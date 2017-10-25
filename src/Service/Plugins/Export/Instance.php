<?php

namespace Casebox\CoreBundle\Service\Plugins\Export;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\BrowserView;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Objects\Plugins\ContentItems;
use Symfony\Component\DependencyInjection\Container;
use Casebox\CoreBundle\Service\Notifications;
use Dompdf\Dompdf;
use Casebox\CoreBundle\Service\DataModel\FilesContent;
use Casebox\CoreBundle\Service\DataModel\Files;
use ZipArchive;

class Instance
{
    public function install()
    {

    }

    public function init()
    {
    }

    protected function getData($p)
    {
        $rez = [];
        if (empty($p)) {
            return $rez;
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        // form columns
        $defaultColumns = $configService->getDefaultGridColumnConfigs();
        $columns = $defaultColumns;

        // retreive data
        $p['start'] = 0;
        $p['rows'] = 500;

        $sr = new BrowserView();
        $results = $sr->getChildren($p);

        if (!empty($results['DC'])) {
            $columns = [];

            foreach ($results['DC'] as $colName => $col) {
                if (@$col['hidden'] !== true) {
                    $columns[$colName] = $col;
                }
            }
        }

        $colTitles = [];
        foreach ($columns as $name => $col) {
            $colTitles[] = empty($defaultColumns[$name]) ? @Util\coalesce($col['title'], $name) : $defaultColumns[$name]['title'];
        }

        //insert header
        $rez[] = $colTitles;

        while (!empty($results['data'])) {
            foreach ($results['data'] as $r) {
                $record = [];
                foreach ($columns as $colName => $col) {

                    if (@$col['xtype'] == 'datecolumn') {
                        $value = Util\dateISOToMysql(@$r[$colName]);

                        if (!empty($col['format'])) {
                            $value = Util\formatMysqlTime($value, $col['format']);

                        } else {
                            $value = Util\formatMysqlTime($value);
                            $tmp = explode(' ', $value);
                            if (!empty($tmp[1]) && ($tmp[1] == '00:00')) {
                                $value = $tmp[0];
                            }
                        }
                        $record[] = $value;

                    } elseif (strpos($colName, 'date') === false) {
                        if (in_array($colName, ['oid', 'cid', 'uid']) && !empty($r[$colName])) {
                            $record[] = User::getDisplayName($r[$colName]);
                        } else {
                            $record[] = @$r[$colName];
                        }
                    }

                }
                $rez[] = $record;
            }

            if (($p['start'] + $p['rows']) < $results['total']) {
                $p['start'] += $p['rows'];
                $results = $sr->getChildren($p);
            } else {
                $results['data'] = [];
            }
        }

        return $rez;
    }

    /**
     * get csv file
     *
     * @param $p object
     */
    public function getCSV($p)
    {
    	if(isset($p['reportId']))
    	{
    		if ($p['reportId'] === 2727)
    		{
    			$xml = $this->getXML($p);
    			header('Content-Type: text/xml; charset=utf-8');
    			header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.xml');
    			header("Pragma: no-cache");
    			header("Expires: 0");
    			echo $xml;
    	
    			exit(0);
    		}
    	}        
    	
    	$rez = [];
		$rez = $this->getCSVContent($p);
		 	
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.csv');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo implode("\n", $rez);
    }

    public function getXML($p)
    {
    	$container = Cache::get('symfony.container');
    	$twig = $container->get('twig');
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
    	$objService = new Objects();
    	$reports = new Notifications();
    	$res = $reports->getReport($p);
    	array_unshift($res['data'], $res['colTitles']);
    	$records = $res['data'];
    	$rez[] = implode(',', array_shift($records));
    	$clients = [];
    	foreach ($records as &$r) {
    		$obj = $objService->load($r);
    		$clientId = $obj['data']['id'];
    		//print_r($obj['data']['data']['sys_data']);
    		$obj['data']['cdate'] = Util\dateMysqlToISO($obj['data']['cdate']);
    		$contentItems = new ContentItems();
    		$items = $contentItems->getData($clientId);
    		$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
    		$files = $filePlugin->getData($clientId);
    
    		$femaNumber = isset($obj['data']['data']['_femanumber'])?str_replace('-','',$obj['data']['data']['_femanumber']):null;
    			
    		if (!empty($femaNumber)) {
    			if(!preg_match('/^[1-9]\d{9}$/', $femaNumber))
    				$femaNumber = null;
    		}
    		$obj['data']['femanumber'] = $femaNumber;
    
    
    		// Select only required properties for result
    		$properties = [
    				'race',
    				'gender',
    				'maritalstatus',
    				'ethnicity',
    				'language',
    				'fematier',
    				'headofhousehold',
    			 'location_type',
    			 'primarylanguage',
    			 'englishspeaker'
    		];
    		foreach ($properties as $property) {
    			unset($obj['data'][$property]);
    			if (!empty($obj['data']['data']['_' . $property])) {
    				$objn = Objects::getCachedObject((is_array($obj['data']['data']['_' . $property])?$obj['data']['data']['_' . $property]['value']:$obj['data']['data']['_' . $property]));
    				$obj['data'][$property] = empty($objn ) ? '' : $objn ->getHtmlSafeName();
    			}
    		}
    		if ($obj['data']['data']['_birthdate'] != null) {
    			$obj['data']['birthdate'] = substr($obj['data']['data']['_birthdate'],0,10);
    		}
    			
    		$arrayproperties = [
    				'at_risk_population',
    				'identified_unmet_needs'
    		];
    		$obj['data']['identified_unmet_needs'] = '';
    		foreach ($arrayproperties as $property) {
    			$values = !empty($obj['data']['data'][$property])?$obj['data']['data'][$property]:null;
    			if ($values != null) {
    				$values = Util\toNumericArray($values);
    				foreach ($values as $v) {
    					$objss = Objects::getCachedObject($v);
    					$obj['data']['identified_unmet_needs'] .= empty($objss) ? '' : ','.str_replace('Yes - ','',$objss->getHtmlSafeName());
    				}
    			}
    		}
    		$caseManagerId = !empty($obj['data']['data']['assigned'])?$obj['data']['data']['assigned']:$obj['data']['cid'];
    		$obj['data']['casemanagerid'] = $caseManagerId;
    		$obj['data']['casemanagername'] = User::getDisplayName($caseManagerId);
    		$obj['data']['casemanageremail'] = User::getEmail($caseManagerId);
    		$obj['data']['identified_unmet_needs'] = trim($obj['data']['identified_unmet_needs'],',');
    		$obj['data']['disastername'] = $configService->get('disaster_declaration_number');
    			
    		$location = isset($obj['data']['data']['_location_type'])?$obj['data']['data']['_femanumber']:null;
    		if (!empty($obj['data']['data']['_fulladdress']))
    		{
    			$addresscomponents = explode(",", $obj['data']['data']['_fulladdress']);
    			$address = [];
    			$address['data']['id'] = $clientId;
    			$address['data']['cdate'] = Util\dateMysqlToISO($obj['data']['cdate']);
    			$address['data']['zipcode'] = '99999';
    			$address['data']['data']['_addresstwo'] = $obj['data']['data']['_addresstwo'];
    			array_pop($addresscomponents);
    			$address['data']['data']['_state'] = trim(array_pop($addresscomponents));
    			$address['data']['data']['_city'] = trim(array_pop($addresscomponents));
    			$address['data']['data']['_addressone'] = join(' ', $addresscomponents);
    			$obj['data']['addresses'][] = $address['data'];
    		}
    		$obj['data']['narrative'] = '';
    
    		foreach ($files['data'] as $file) { //consent form signed
    			$obj['data']['datereleasesigned'] = substr($file['cdate'],0,10);
    		}
    			
    		foreach ($items['data'] as $item) {
    			if ($item['template_id'] == 311) //address
    			{
    				$address = $objService->load($item);
    				$address['data']['cdate'] = Util\dateMysqlToISO($address['data']['cdate']);
    				$address['data']['zipcode'] = '99999';
    				$addresscomponents = explode(",", $address['data']['data']['_fulladdress']);
    				array_pop($addresscomponents);
    				$address['data']['data']['_state'] = trim(array_pop($addresscomponents));
    				$address['data']['data']['_city'] = trim(array_pop($addresscomponents));
    				$address['data']['data']['_addressone'] = join(' ', $addresscomponents);
    				$obj['data']['addresses'][] = $address['data'];
    			}
    			elseif ($item['template_id'] == 289) //family member
    			{
    				$fm = $objService->load($item);
    				if (isset($fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber']))
    				{
    					$obj['data']['alternatecontactphonenumber'] = $fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber'];
    				}
    				if (isset($fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber']))
    				{
    					$obj['data']['alternatecontactotherphonenumber'] = $fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber'];
    				}
    			}
    			elseif ($item['template_id'] ==440) //Housing
    			{
    				$fm = $objService->load($item);
    				$properties = [
    						'clientdamagerating',
    						'predisasterliving'
    				];
    				foreach ($properties as $property) {
    					unset($obj['data'][$property]);
    					if ($fm['data']['data']['_' . $property] != null) {
    						$objn = Objects::getCachedObject((is_array($fm['data']['data']['_' . $property])?$fm['data']['data']['_' . $property]['value']:$fm['data']['data']['_' . $property]));
    						$obj['data'][$property] = empty($objn ) ? '' :  str_replace('Room Apartment','Room, Apartment',$objn ->getHtmlSafeName());
    					}
    				}
    				if (isset($fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment']))
    				{
    					$obj['data']['clientdamageratingother'] = $fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment'];
    				}
    			}
    			elseif ($item['template_id'] ==527) //Casenote
    			{
    				$fm = $objService->load($item);
    				$obj['data']['narrative'] .= '['.$fm['data']['cdate']. '] '.$fm['data']['data']['_casenote'];
    			}
    			elseif ($item['template_id'] ==607) //referral
    			{
    				$fm = $objService->load($item);
    				$fm['data']['cdate'] = Util\dateMysqlToISO($fm['data']['cdate']);
    				$fm['data']['description'] = (isset($fm['data']['data']['_associatedneed'])?$fm['data']['data']['_associatedneed'].' ':'') . (isset($fm['data']['data']['_associatedneed'])?$fm['data']['data']['_associatedgoal'].' ':'');
    				$obj['data']['services'][] = $fm['data'];
    				if (isset($fm['data']['data']['_provider']))
    				{
    					$obj['data']['referrals'][] = $fm['data'];
    				}
    			}
    		}
    		$clients[] = $obj['data'];
    	}
    	$vars = [
    			'clients' => $clients,
    			'disasterEventDate' => $configService->get('disaster_date'),
    			'disasterName' => $configService->get('disaster_name'),
    			'generationStamp' => date('Y-m-d\TH:i:s\Z'),
    			'agencyId' => !empty($configService->get('agency_id'))?$configService->get('agency_id'):'262626',
    			'agencyName' => !empty($configService->get('agency_name'))?$configService->get('agency_name'):'OHSEPR',
    			'caseManagerEmail' => !empty($configService->get('agency_name'))?$configService->get('disaster_email'):'OHSEPR',
    			'disasterDRONumber' => $configService->get('disaster_deron_number'),
    			'cm_phone' =>$configService->get('disaster_phone_number'),
    	];
    	$html = $twig->render('CaseboxCoreBundle:email:dcds_1_1.xml.twig', $vars);
    	return $html;
    }    
    
  public function getExport($p)
    {
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        $zipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.time().'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		$zip = new ZipArchive;
		$zip->open($zipname, ZipArchive::CREATE);
    	$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));
		$count = 0;
        foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
                	$t = strip_tags($r[$t]);

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        if ($count < 100)
        {
        $clientId = $r['id'];
    	$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
		$files = $filePlugin->getData($clientId);
			
		foreach ($files['data'] as $file) {
			$fileId = $file['id'];
		}		
		
		$r = Files::read($fileId);
		if (!empty($r)) {
            $content = FilesContent::read($r['content_id']);
			$file = $configService->get('files_dir').$content['path'].DIRECTORY_SEPARATOR.$content['id'];
			$zip->addFile($file, $clientId.'consentform.pdf');
		}			
		
		$export = new Instance();
		
		$html = $export->getPDFContent($clientId);
		//echo($html);
		
		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();
		$recoveryPlan = $dompdf->output();
		
		$zip->addFromString($clientId.'recoveryplan.pdf',$recoveryPlan);
		  } //count less than 100
		  $count++;
		}
		$zip->addFromString('records.csv',implode("\n", $rez));
				$zip->close();

        header('Content-Type: application/zip; charset=utf-8');
        header('Content-Disposition: attachment; filename='.date('Y-m-d_Hi').'.zip');
        header("Pragma: no-cache");
        header("Expires: 0");
        readfile($zipname);
        exit(0);
    }
	
	public function getPDF($p)
	{
		
		$container = Cache::get('symfony.container');
		$twig = $container->get('twig');
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        // Check if object id is numeric
        if (is_numeric($p)) {		
        	$html = $this->getPDFContent($p);
        }
		else
		{
			if(isset($p['reportId']))
			{
			if ($p['reportId'] === 2727)
			{
				$this->getExport($p);
			} 
			}
			$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));

        	foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
                	$t = strip_tags($r[$t]);

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        	}	
			
			date_default_timezone_set("America/New_York");

			$vars = [
				'title' => $res['title'],
				'columnTitle'=> $res['columns'],
				'services'=>$records,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];
			$html = $twig->render('CaseboxCoreBundle:email:reports.html.twig', $vars);		
		}
		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);

		// (Optional) Setup the paper size and orientation
		$dompdf->setPaper('A4', 'landscape');

		// Render the HTML as PDF
		$dompdf->render();
		$dompdf->stream('recovery_plan'.$p, array("Attachment" => false));

		exit(0);
	}

    public function getHTML($p)
    {
        $rez = [];
        $records = $this->getData($p);

        $rez[] = '<th>'.implode('</th><th>', array_shift($records)).'</th>';

        foreach ($records as $r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);
                $record[] = $t;
            }
            $rez[] = '<td>'.implode('</td><td>', $record).'</td>';
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.html');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo '<!DOCTYPE html>
            <html>
            <header>
                <meta http-equiv="content-type" content="text/html; charset=utf-8" >
            </header>
            <body>
            <table border="1" style="border-collapse: collapse">
            <tr>';
        echo implode("</tr>\n<tr>", $rez);
        echo '</tr></table></body></html>';
    }
    
    public function getCSVContent($p)
    {
    	$rez = [];
    	if(isset($p['reportId']))
		{
			$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));

        	foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
			if (isset($r[$t]))
			{
				$t = strip_tags($r[$t]);
			}
			else
			{
				$t = '';
			}

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        	}

		}
		else
		{
			$records = $this->getData($p);
			       $rez[] = implode(',', array_shift($records));

        foreach ($records as &$r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);

                if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        }
		}
		return $rez;
    }
    
        public function getPDFContent($p)
    {
    	$container = Cache::get('symfony.container');
		$twig = $container->get('twig');
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');	
        	$services = null;
			$objService = new Objects();
			$obj = $objService->load(['id' => $p]);
			//print_r($obj['data']['data']['sys_data']);
			
			$contentItems = new ContentItems();
			$items = $contentItems->getData($p);
			$femaNumber = isset($obj['data']['data']['_femanumber'])?$obj['data']['data']['_femanumber']:null;
			
			if (empty($femaNumber)) {
				$femaNumber = 'N/A';
			}
			if (!empty($obj['data']['data']['_location_type']) && !empty(Objects::getCachedObject($obj['data']['data']['_location_type'])))
			{
				$location = $objService->load(['id' => $obj['data']['data']['_location_type']]);
			}
			 foreach ($items['data'] as $item) {
					if ($item['template_id'] == 607)
					{
						 $comments = '';
						 $service = $objService->load($item);
						 $referralType = Objects::getCachedObject($service['data']['data']['_referraltype']['value']);	
						 $refferalTypeValue = empty($referralType) ? 'N/A' : $referralType->getHtmlSafeName();
						 $referralSubType = Objects::getCachedObject($service['data']['data']['_referraltype']['childs']['_referralservice']);						 
						 $refferalSubTypeValue = empty($referralSubType) ? 'N/A' : $referralSubType->getHtmlSafeName();
						 if (!empty($service['data']['data']['_provider']) && !empty(Objects::getCachedObject($service['data']['data']['_provider'])))
						 {
						    $resource = $objService->load(['id' => $service['data']['data']['_provider']]);		
						 }				
						 else
						 {
							unset($resource);
						 }	 
						 //$resourceValue = empty($resource) ? 'N/A' : $resource->getHtmlSafeName();
						 //print_r($service);
						 if (!empty($service['data']['data']['_commentgroup']))
						 {
							foreach ($service['data']['data']['_commentgroup'] as $key) {
								 $comments = $comments. $key['childs']['_comments'] . ' ('.Util\formatMysqlDate($key['childs']['_commentdate'], Util\getOption('short_date_format')).'),';
							}
							$comments = trim($comments,',') .$service['data']['data']['_commentgroup']['childs']['_comments']. ' ('.Util\formatMysqlDate($service['data']['data']['_commentgroup']['childs']['_commentdate'], Util\getOption('short_date_format')).'),';
						 }
						 $services[] = [
							'associatedneed' => !empty($service['data']['data']['_associatedneed'])?$service['data']['data']['_associatedneed']:'',
							'associatedgoal' => !empty($service['data']['data']['_associatedgoal'])?$service['data']['data']['_associatedgoal']:'',			
							'referraltype' => $refferalTypeValue,
							'referralsubtype' => $refferalSubTypeValue,
							'resourceagencyname' => empty($resource) ? 'N/A' : $resource['data']['data']['_providername'],
							'resourceagencycontactinformation' => empty($resource) ? 'N/A' : (!empty($resource['data']['data']['_streetaddress'])?$resource['data']['data']['_streetaddress']:''). ' ' .(!empty($resource['data']['data']['_city'])?$resource['data']['data']['_city']:''). ' ' .(!empty($resource['data']['data']['_state'])?$resource['data']['data']['_state']:''). ' ' .(!empty($resource['data']['data']['_zipcode'])?$resource['data']['data']['_zipcode']:''). ' ' .(!empty($resource['data']['data']['_phonenumbers'])?$resource['data']['data']['_phonenumbers']:''),
							'pointofcontact' => (empty($resource) || empty($resource['data']['data']['_pointofcontact']))? 'N/A' : $resource['data']['data']['_pointofcontact'],
							'referralappointmentdatetime' =>(!empty($service['data']['data']['_appointmentdate'])?Util\formatMysqlDate($service['data']['data']['_appointmentdate'], Util\getOption('short_date_format')):''). ' ' . (!empty($service['data']['data']['_appointmenttime'])?$service['data']['data']['_appointmenttime']:''),
							'targetcompletiondate' =>  Util\formatMysqlDate($service['data']['data']['_targetcompletiondate'], Util\getOption('short_date_format')),
							'comments' => str_replace('()','',trim($comments,','))
						];	
					}
				}
			$v = isset($obj['data']['data']['assigned'])?$obj['data']['data']['assigned']:null;
			
			if (empty($v)) {
				$assigned = 'N/A';
			}else 
			{
				 $assigned = User::getDisplayName($v);
				 //echo(User::getUserData($v)['phone']);
			}
			
			$vars = [
				'client_lastname' => $obj['data']['data']['_lastname'],
				'client_firstname' => $obj['data']['data']['_firstname'],			
				'disaster_declaration_number' => $configService->get('disaster_declaration_number'),
				'disaster_site_address' => empty($location)?'N/A': $location['data']['data']['_locationname'] .' - '.$location['data']['data']['_locationaddress'].' '.$location['data']['data']['_locationcity'] ,
				'cm_phone' => $configService->get('disaster_phone_number'),
				'cm_assigned' => $assigned,
				'cm_phone' =>$configService->get('disaster_phone_number'),
				'plan_creation_date' => date("F j, Y, g:i a"),
				'fema_registration_number' => $femaNumber,
				'client_id' => $obj['data']['id'],
				'services' =>
					$services,
			];
			$html = $twig->render('CaseboxCoreBundle:email:recovery-plan.html.twig', $vars);
		return $html;
    }
    
}
