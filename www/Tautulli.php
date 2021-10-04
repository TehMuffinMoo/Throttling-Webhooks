<?php
  $SkipCSS = true;
  require_once '..\Scripts\inc.php';
  $ThrottledTagName = $GLOBALS['config']['ThrottledTagName'];
  $DateTime = date("d-m-Y h:i:s");
  
  ############# DEBUG #############
  $request = file_get_contents('php://input');
  $req_dump = print_r( $request, true );
  $fp = file_put_contents( 'Tautulli-Request.log', $req_dump );
  #################################
  
  ## Functions
  function REST($Uri, $JsonData, $Method) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $Uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($JsonData)));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $Method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,$JsonData);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $response;
  }
  
  ## Get Headers
  $headers = getallheaders();
  $APIKey = $headers['Authorization'];

  ## Check for valid data and API Key
  if (file_get_contents( 'php://input' ) == null) {
     die("PHP Input Empty");
  }
  if ($APIKey != $GLOBALS['config']['WebhookAPIToken']) {
     die("Bad API Key");
  }
  
  ## Decode POST Data
  $POST_DATA = json_decode(file_get_contents('php://input'), true);
  
  ## Check tvdbId exists
  if (empty($POST_DATA['tvdbid'])) {
	  die("Empty tvdbId");
  }
  
  ## Set Sonarr Details
  $SonarrHost = $GLOBALS['config']['SonarrURL'];
  $SonarrAPIKey = $GLOBALS['config']['SonarrAPIKey'];
  
  ## Set Sonarr Tag Endpoint
  $SonarrTagEndpoint = $SonarrHost.'/tag?apikey='.$SonarrAPIKey;
  $SonarrTagObj = json_decode(file_get_contents($SonarrTagEndpoint));
  $ThrottledTagKey = array_search($ThrottledTagName, array_column($SonarrTagObj, 'label'));
  $ThrottledTag = $SonarrTagObj[$ThrottledTagKey]->id;
  
  ## Kill if Throttled tag is missing in Sonarr. May add auto creation of tag in future.
  if (empty($ThrottledTag)) {
	die("Throttled tag missing");
  }
	
  ## Set Sonarr Search Endpoint
  $userSearch = "tvdbid:".$POST_DATA['tvdbid'];
  $SonarrLookupEndpoint = $SonarrHost.'/series/lookup?term='.$userSearch.'&apikey='.$SonarrAPIKey;

  ## Query Sonarr Lookup API
  $SonarrLookupObj = json_decode(file_get_contents($SonarrLookupEndpoint));

  ## Check if Sonarr ID Exists
  if (empty($SonarrLookupObj[0]->id)) {
    die("TV Show not in Sonarr database.");
  }
  
  ## Set Sonarr Series Endpoint
  $SeriesID = $SonarrLookupObj[0]->id;
  $SonarrSeriesEndpoint = $SonarrHost.'/series/'.$SeriesID.'?apikey='.$SonarrAPIKey;
  
  ## Query Sonarr Series API
  $SonarrSeriesObj = json_decode(file_get_contents($SonarrSeriesEndpoint));
  
  ## Check if TV Show has Throttling tag
  if (in_array($ThrottledTag,$SonarrSeriesObj->tags)) {
	$SonarrEpisodeEndpoint = $SonarrHost.'/episode/?apikey='.$SonarrAPIKey.'&seriesId='.$SeriesID;
	$SonarrEpisodeObj = json_decode(file_get_contents($SonarrEpisodeEndpoint));
	
	## Find next incremental episode to download
	foreach ($SonarrEpisodeObj as $Episode) {
		if ($Episode->hasFile == false && $Episode->seasonNumber != "0" && $Episode->monitored == true) {
		  $Response = $DateTime.' - Search request sent for: '.$SonarrSeriesObj->title.' - S'.$Episode->seasonNumber.'E'.$Episode->episodeNumber.' - '.$Episode->title.PHP_EOL;
		  file_put_contents( 'Tautulli.log', $Response, FILE_APPEND );
          echo $Response;
          
          ## Send Scan Request to Sonarr
          $SonarrCommandEndpoint = $SonarrHost."/command/?apikey=".$SonarrAPIKey; // Set Sonarr URI
          $EpisodesToSearch[] = $Episode->id; // Episode IDs
          $SonarrSearchPostData['name'] = "EpisodeSearch"; // Sonarr command to run
          $SonarrSearchPostData['episodeIds'] = $EpisodesToSearch; // Episode IDs Array
          $SonarrSearchPostData = json_encode($SonarrSearchPostData); // POST Data
          REST($SonarrCommandEndpoint, $SonarrSearchPostData, 'POST'); // Send Scan Command to Sonarr
		  $MoreEpisodesAvailable = true;
          break;
        }
	}
	if (empty($MoreEpisodesAvailable)) {
      $Response = $DateTime.' - All aired episodes are available. Removing throttling from: '.$SonarrSeriesObj->title.' and marking as monitored.'.PHP_EOL;
	  file_put_contents( 'Tautulli.log', $Response, FILE_APPEND );
	  echo $Response;
	  
	  ## Find Throttled Tag and remove it
	  $SonarrSeriesObjtags[] = $SonarrSeriesObj->tags;
	  $ArrKey = array_search($ThrottledTag, $SonarrSeriesObjtags['0']);
	  unset($SonarrSeriesObjtags['0'][$ArrKey]);
	  $SonarrSeriesObj->tags = $SonarrSeriesObjtags['0'];
	  ## Mark TV Show as Monitored
	  $SonarrSeriesObj->monitored = true;
      ## Submit data back to Sonarr
      $SonarrSeriesJSON = json_encode($SonarrSeriesObj); // Convert back to JSON
      $SonarrSeriesPUT = REST($SonarrSeriesEndpoint, $SonarrSeriesJSON, 'PUT'); // POST Data to Sonarr
    }
  } else {
    die("TV Show not throttled.");
  }
