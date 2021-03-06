<?php
class S5ReadLecturerSchedules implements iSubscript {
  public function execute($oMysqli) {
	
	//Log start proces of updating
	$oMysqli->query("DELETE FROM stats_updates WHERE date = CURDATE();");
    $oMysqli->query("INSERT INTO stats_updates (date, starttime) VALUES (CURDATE(), CURTIME());");
    
    //Read normal schedules
    if ($hDir = opendir(dirname(__FILE__) . "/../../cache/lecturer/")) {
      while (false !== ($sFile = readdir($hDir))) {
        if ($sFile == '.' || $sFile == '..') { 
          continue; 
        }
        $sFullPath = dirname(__FILE__) . "/../../cache/lecturer/" . $sFile;
        if (is_file($sFullPath) && strpos($sFile, ".ics") !== FALSE) {
          $sLecturerCode = substr($sFile, 0, -4);
          $this->readLecturerSchedule($oMysqli, $sFullPath, $sLecturerCode, false);
        } else {
          die("File " . $sFullPath . " doesn't seem to be a file. Nice!");
        }
      }
    } else {
      die("Can't run the given scripts, no valid period / subdirectory");
    }
    
    //Read beta schedules
    if ($hDir = opendir(dirname(__FILE__) . "/../../cache/lecturer_beta/")) {
      while (false !== ($sFile = readdir($hDir))) {
        if ($sFile == '.' || $sFile == '..') { 
          continue; 
        }
        $sFullPath = dirname(__FILE__) . "/../../cache/lecturer_beta/" . $sFile;
        if (is_file($sFullPath) && strpos($sFile, ".ics") !== FALSE) {
          $sLecturerCode = substr($sFile, 0, -4);
          $this->readLecturerSchedule($oMysqli, $sFullPath, $sLecturerCode, true);
        } else {
          die("File " . $sFullPath . " doesn't seem to be a file. Nice!");
        }
      }
    } else {
      die("Can't run the given scripts, no valid period / subdirectory");
    }
  }	
  
  private function readLecturerSchedule($oMysqli, $sFullPath, $sLecturerId, $bIsBeta) {
    echo "Reading lecturer schedule from " . $sLecturerId . " ";
    //Retrieve data
    $oiCal = getiCalEvents($sFullPath);
    $sLecturerString = $oiCal->cal['VCALENDAR']['X-WR-CALNAME'];
    $sLecturerName = trim(substr($sLecturerString, 20));
    
    //Add lecturer to database
    $oMysqli->query("INSERT INTO lecturer(id, name) VALUES (\"" . $sLecturerId . "\", \"" . $sLecturerName . "\") ON DUPLICATE KEY UPDATE name = \"" . $sLecturerName . "\";");
    
    if ($oiCal->hasEvents()) {
      $aEvents = $oiCal->events();
    
      foreach ($aEvents as $aEvent) {
        $sDate = date("Y-m-d", $oiCal->iCalDateToUnixTimestamp($aEvent['DTSTART']));
        $sStartTime = date("G:i:00", $oiCal->iCalDateToUnixTimestamp($aEvent['DTSTART']));
        $sEndTime = date("G:i:00", $oiCal->iCalDateToUnixTimestamp($aEvent['DTEND']));
      
        $sSummary = $aEvent['SUMMARY'];
        $sDescription = $aEvent['DESCRIPTION'];
        $sLocation = $aEvent['LOCATION'];
      
      
        //Special types
        $sActivityId = $sSummary;
      
        //Extract classes and activitytype from description
        $aDescriptionParts = preg_split("/[,|]+/", $sDescription);
        $iDescriptionPartsLength = count($aDescriptionParts);
        $aClasses = array();
        for($i = 0; $i < ($iDescriptionPartsLength-1); $i++) {
          $sClass = trim($aDescriptionParts[$i]);
          $sClass = str_replace("\\", "", $sClass);
          $sClass = str_replace("Klas ", "", $sClass);
          if (strlen($sClass)!=0) {
            array_push($aClasses, $sClass); 
          }
        }
        $sActivityTypeId = trim($aDescriptionParts[$iDescriptionPartsLength - 1]);
        $aClasses = array_unique($aClasses);
        
        //$sClasses = trim(substr($sDescription, 5, strpos($sDescription, "(") - 6));
        //preg_match_all("/[A-Za-z0-9]+/", $sClasses, $aClasses);
        //$aClasses = array_unique($aClasses[0]);
        //$sActivityTypeId = trim(substr($sDescription, strpos($sDescription, ")") + 1));
      
        //Extract class id from location
        $aLocationParts = preg_split("/[,]+/", $sLocation);
        $aRooms = array_unique(preg_split("/[\s\|]+/", trim($aLocationParts[1])));
      
        //Create lecturers array
        $aLecturers = array($sLecturerId);
      
        sort($aRooms);
        sort($aClasses);
        sort($aLecturers);
      
        //Add entry
        $this->createEntry($oMysqli, $sDate, $sStartTime, $sEndTime, $aRooms, $aClasses, $aLecturers, $sActivityId, $sActivityTypeId, $sDescription, $sSummary, $sLocation, $bIsBeta);
        echo ".";
      }
    }
    echo "OK!\n";
  }
  
  private function createEntry($oMysqli, $sDate, $sStarttime, $sEndtime, $aRooms, $aClasses, $aLecturers, $sActivityId, $sActivityTypeId, $sDescription, $sSummary, $sLocation, $bBeta) {
    if (sizeof($aRooms) != 0) {
      $sRooms = implode(",", $aRooms);
    } else {
      $sRooms = "NULL";
    }
    //Create lesson
    $sQuery = "INSERT INTO lesson(date, starttime, endtime, startlecturehour, endlecturehour, activity_id, activitytype_id, description, summary, location, rooms, beta) VALUES (" . 
    "\"" . $sDate . "\", " .
    "\"" . $sStarttime . "\", " .
    "\"" . $sEndtime . "\", " .
    "(SELECT lecturehour FROM lecturetimes WHERE lecturetimes.starttime = \"" . $sStarttime . "\"), " .
    "(SELECT lecturehour FROM lecturetimes WHERE lecturetimes.endtime = \"" . $sEndtime . "\"), " .
    "\"" . $sActivityId . "\", " . 
    "\"" . $sActivityTypeId . "\", " . 
    "\"" . $sDescription . "\", " .
    "\"" . $sSummary . "\", " .
    "\"" . $sLocation . "\", " .
    "\"" . $sRooms . "\", " .
    "\"" . ($bBeta == true ? 1 : 0) . "\"" . 
    ");";
    
    if ($oMysqli->query($sQuery)) {
      $iLessonId = $oMysqli->insert_id;
    } else {
            $sQuery = "SELECT id FROM lesson WHERE date=\"" . $sDate . "\" AND starttime=\"" . $sStarttime . "\" AND endtime=\"" . $sEndtime . "\" AND activity_id = \"" . $sActivityId . "\" AND activitytype_id = \"" . $sActivityTypeId . "\" AND rooms = \"" . $sRooms . "\";";
      $oResult = $oMysqli->query($sQuery);
      $oObj = $oResult->fetch_object();
      $iLessonId = $oObj->id;
    }
    
    //Add activities and activity types
    if ($sActivityId!="") {
      $oMysqli->query("INSERT INTO activity VALUES (\"" . $sActivityId . "\");");
    }
    if ($sActivityTypeId!="") {
      $oMysqli->query("INSERT INTO activitytype VALUES (\"" . $sActivityTypeId . "\");");
    }
    
    //Add rooms, classes, lecturers
    foreach ($aRooms as $sRoom) {
      //TODO: splitting in buildingfloor, buildingpart and building 
      //Add room to room table when not exists
      $oMysqli->query("INSERT INTO room(id) VALUES (\"" . $sRoom . "\");");

      $oMysqli->query("INSERT INTO lessonrooms VALUES (" . $iLessonId . ", \"" . $sRoom . "\");");

    }
    foreach ($aClasses as $sClass) {
      //Add class to class table when not exists
      $oMysqli->query("INSERT INTO class(id) VALUES (\"" . $sClass . "\");");
      
      $oMysqli->query("INSERT INTO lessonclasses VALUES (" . $iLessonId . ", \"" . $sClass . "\");");
      
    }
    foreach ($aLecturers as $sLecturer) {
      //Add lecturer to lecturer table when not exists
      $oMysqli->query("INSERT INTO lecturer(id) VALUES (\"" . $sLecturer . "\");");
      
      $oMysqli->query("INSERT INTO lessonlecturers VALUES (" . $iLessonId . ", \"" . $sLecturer . "\");");
      
    }
  }
}
?>
