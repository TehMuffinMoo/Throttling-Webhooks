<?php
  require_once '..\Scripts\inc.php';
  
  ############# DEBUG #############
  $request = file_get_contents('php://input');
  $req_dump = print_r( $request, true );
  $fp = file_put_contents( 'request.log', $req_dump );
  #################################
  
  ## Set Parameters
  $SeasonCountThreshold = $GLOBALS['config']['SeasonCountThreshold'];
  $EpisodeCountThreshold = $GLOBALS['config']['EpisodeCountThreshold'];
  $EpisodeSearchCount = $GLOBALS['config']['EpisodeSearchCount'];
  $ThrottledTagName = $GLOBALS['config']['ThrottledTagName'];
  
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
  if (empty($POST_DATA['media']['tvdbId'])) {
	  die("Empty tvdbId");
  }
  
  ## Check Request Type
  if ($POST_DATA['media']['media_type'] == "tv") {
	  
	## Sleep to allow Sonarr to update. Might add a loop checking logic here in the future.
	sleep(10);

    ## Set Sonarr Details
    $SonarrHost = $GLOBALS['config']['SonarrURL'];
    $SonarrAPIKey = $GLOBALS['config']['SonarrAPIKey'];
	
	## Set Sonarr Tag Endpoint
    $SonarrTagEndpoint = $SonarrHost.'/tag?apikey='.$SonarrAPIKey;
	$SonarrTagObj = json_decode(file_get_contents($SonarrTagEndpoint));
	
	if (empty($SonarrTagObj)) {
	  die("Getting Sonarr Tags Failed");
	}
	
	$ThrottledTagKey = array_search($ThrottledTagName, array_column($SonarrTagObj, 'label'));
	$ThrottledTag = $SonarrTagObj[$ThrottledTagKey]->id;
	
	## Kill if Throttled tag is missing in Sonarr. May add auto creation of tag in future.
	if (empty($ThrottledTag)) {
	  die("Throttled tag missing");
    }
	
    ## Set Sonarr Search Endpoint
    $userSearch = "tvdbid:".$POST_DATA['media']['tvdbId'];
    $SonarrLookupEndpoint = $SonarrHost.'/series/lookup?term='.$userSearch.'&apikey='.$SonarrAPIKey;

    ## Query Sonarr Lookup API
    $SonarrLookupJSON = file_get_contents($SonarrLookupEndpoint);
    $SonarrLookupObj = json_decode($SonarrLookupJSON);
	
	## Check if Sonarr ID Exists
	if (empty($SonarrLookupObj[0]->id)) {
      die("TV Show not in Sonarr database.");
	}
  
    ## Set Sonarr Series Endpoint
    $SeriesID = $SonarrLookupObj[0]->id;
    $SonarrSeriesEndpoint = $SonarrHost.'/series/'.$SeriesID.'?apikey='.$SonarrAPIKey;
  
    ## Query Sonarr Series API
    $SonarrSeriesJSON = file_get_contents($SonarrSeriesEndpoint);
    $SonarrSeriesObj = json_decode($SonarrSeriesJSON);

    ## Check Season Count & Apply Throttling Tag if neccessary
    $EpisodeCount = 0;
    foreach ($SonarrSeriesObj->seasons as $season) {
      $EpisodeCount += $season->statistics->totalEpisodeCount;
    }
  
    $SeasonCount = $SonarrSeriesObj->seasonCount;
    if ($SeasonCount > $SeasonCountThreshold) {
      $SonarrSeriesObjtags[] = $ThrottledTag;
      $SonarrSeriesObj->tags = $SonarrSeriesObjtags;
      $SonarrSeriesObj->monitored = false;
      $Search = "searchX";
    } else if ($EpisodeCount > $EpisodeCountThreshold) {
      $SonarrSeriesObjtags[] = $ThrottledTag;
      $SonarrSeriesObj->tags = $SonarrSeriesObjtags;
      $SonarrSeriesObj->monitored = false;
      $Search = "searchX";
    } else {
      $SonarrSeriesObj->monitored = true;
	  $SonarrSeriesObj->addOptions = new stdClass();
      $SonarrSeriesObj->addOptions->searchForMissingEpisodes = true;
      $Search = "searchAll";
    };

    ## Set Sonarr Command Endpoint
    $SonarrCommandEndpoint = $SonarrHost."/command/?apikey=".$SonarrAPIKey; // Set Sonarr URI
  
    ## Initiate Searching
    if ($Search == "searchAll") {
      $SonarrSearchPostData['name'] = "SeriesSearch";
      $SonarrSearchPostData['seriesId'] = $SeriesID;
      $SonarrSearchPostData = json_encode($SonarrSearchPostData);
      REST($SonarrCommandEndpoint, $SonarrSearchPostData, 'POST'); // Send Scan Command to Sonarr
    } else if ($Search == "searchX") {
      $SonarrEpisodeEndpoint = $SonarrHost."/episode/?seriesId=".$SeriesID."&apikey=".$SonarrAPIKey; // Set Sonarr URI
      $Episodes = json_decode(file_get_contents($SonarrEpisodeEndpoint), true); // Get Episode Information from Sonarr
      foreach ($Episodes as $Key => $Episode) {
        if ($Episode['seasonNumber'] != "0" && $Episode['hasFile'] != true) {
          $EpisodesToSearch[] = $Episode['id'];
        }
      }
      $SonarrSearchPostData['name'] = "EpisodeSearch";
      $SonarrSearchPostData['episodeIds'] = array_slice($EpisodesToSearch,0,$EpisodeSearchCount);
      $SonarrSearchPostData = json_encode($SonarrSearchPostData);
      REST($SonarrCommandEndpoint, $SonarrSearchPostData, 'POST'); // Send Scan Command to Sonarr
    }
   
    ## Submit data back to Sonarr
    $SonarrSeriesJSON = json_encode($SonarrSeriesObj); // Convert back to JSON
    $SonarrSeriesPUT = REST($SonarrSeriesEndpoint, $SonarrSeriesJSON, 'PUT'); // POST Data to Sonarr
    echo json_encode($SonarrSeriesObj, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); // Echo Result
    http_response_code(201);
	
    ############# DEBUG #############
    $res_dump = print_r( $SonarrSeriesObj, true );
    $fpres = file_put_contents( 'response.log', $res_dump );
    #################################
	
  } else {
    die("Not a TV Show Request");
  }