<?php
include_once 'common_functions.php';
include_once 'db_conn.php';

DEFINE("SERVER_URL", "https://opendata.fmi.fi/wfs");
DEFINE("STORED_QUERY_AVG_OBSERVATION", "fmi::observations::weather::daily::multipointcoverage");

DEFINE("MINYEAR", 2015);

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


function getObservationStationOptions($obsStations, $selectedStation) {
    $retOptions = "";
    foreach ($obsStations as $key => $value) {
            $selectedStr = $key == $selectedStation ? "selected" : "";
            $retOptions.= "<option value=\"{$key}\" {$selectedStr}>{$value}</option>";
    }
    return $retOptions;
}

function getObservationStations($conn) {
    $retArr = array();
    if ($result = $conn->query("CALL getStations ();")) {
        while ($row = $result->fetch_assoc()) {
            $stationId = $row["idobservation_station"];
            $stationName = $row["observationstation_name"];
            $retArr[$stationId] = $stationName;
        }
    } else {
        error_log($conn->error);
    }
    return $retArr;
}

function getObservations($conn, $locationSelect) {
    $retArr = array();
    if ($result = $conn->query("CALL getObservations ({$locationSelect});")) {
        while ($row = $result->fetch_assoc()) {
            $datepart = $row["datepart"];
            array_push($retArr, $datepart);
        }
    } else {
        error_log($conn->error);
    }
    return $retArr;
}

$obsStations = getObservationStations($conn);

function missingObservations($currentObservations, $monthSelect) {
    $retArr = array();
    $thisYear = intval(date("Y"));
    for ($i = MINYEAR; $i <= $thisYear; $i++) {
        $chkStartDate = DateTime::createFromFormat("Ymd", "{$i}{$monthSelect}01");
        $daysInMonth = date("t", $chkStartDate->getTimestamp());
        for ($j = 1; $j <= $daysInMonth; $j++) {
            $dayStr = ($j < 10) ? "0".$j : $j;
            $chkStr = "{$i}{$monthSelect}{$dayStr}";
            if (!in_array($chkStr, $currentObservations)) array_push($retArr, $chkStr);
        }
    }

    return $retArr;
}

function isNext($cur, $next) {
    $chkStartDate = DateTime::createFromFormat("Ymd", $cur);
    return strtotime("tomorrow", $chkStartDate->getTimestamp()) == DateTime::createFromFormat("Ymd", $next)->getTimestamp();
}

function missingObservationsAsSeries() {
    $retArr = array();

    return $retArr;
}

function getTemperatures($missingObservations, $location, $monthSelect) {
    $thisYear = date("Y");
    $nextMonthStr = (intval($monthSelect)+1) < 10 ? "0".(intval($monthSelect)+1) : "".(intval($monthSelect)+1);
    $lastMonth = intval($monthSelect) == 12 ? (intval($thisYear)+1)."-01" : $thisYear."-".$nextMonthStr;
    $query_param = "parameters=tday,tmin,tmax&starttime={$thisYear}-{$monthSelect}-01T00:00:00Z&endtime={$lastMonth}-01T00:00:00Z&place=".$location;
    return download_remote_file_with_curl(SERVER_URL, STORED_QUERY_AVG_OBSERVATION, $query_param);
}

function parseValues($tmpValues) {
    $tmpElements = $tmpValues->getElementsByTagNameNS('http://www.opengis.net/gml/3.2', 'doubleOrNilReasonTupleList');
    if ($tmpElements->length == 1) return $tmpElements->item(0)->nodeValue;

    $dateElements = $tmpValues->getElementsByTagNameNS('http://www.opengis.net/gmlcov/1.0', 'positions');
    if ($dateElements->length == 1) return $dateElements->item(0)->nodeValue;
}

$monthSelect = isset($_GET["mon"]) ? $_GET["mon"] : date("m");

$locationSelect = isset($_GET["loc"]) ? $_GET["loc"] : "";
if ($locationSelect != "") {
    $currentObservations = getObservations($conn, $locationSelect);
    $missingObservations = missingObservations($currentObservations, $monthSelect);
    $tmpValues = getTemperatures($missingObservations, $obsStations[$locationSelect], $monthSelect);
    parseValues($tmpValues);
}
?>

<!doctype html>

<html lang="en">
<head>
    <meta charset="utf-8">

    <title>The Temperature History</title>
    <meta name="description" content="The Temperature History">
    <meta name="author" content="Fuison">

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
</head>

<body>
    <div class="container">
        <select class="custom-select" id="locationSelect">
            <option selected>Choose observation station</option>
            <?= getObservationStationOptions($obsStations, $locationSelect) ?>
        </select>
        <div id="results">
        </div>
    </div>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <!-- <script src="js/metolib.js"></script> -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
    <!-- <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.20/lodash.min.js"></script> -->
    <script src="./js/map.js"></script>
    <script src="./js/index.js"></script>
    <script src="./js/temphistory.js"></script>
    <script type="text/javascript">
        // google.charts.load("current", {packages:["corechart"]});
        // google.charts.setOnLoadCallback(getTemps);
        // var SERVER_URL = "https://opendata.fmi.fi/wfs";
        // var STORED_QUERY_AVG_OBSERVATION = "fmi::observations::weather::daily::multipointcoverage";
        // var STORED_QUERY_OBSERVATION = "fmi::observations::weather::multipointcoverage";

        // Metolib.WfsRequestParser = new Metolib.WfsRequestParser();

        // var thisMonth = [];
    </script>
</body>
</html>
