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
        $result->free();
        $conn->next_result();
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
        $result->free();
        $conn->next_result();
    } else {
        error_log($conn->error);
    }
    return $retArr;
}

$obsStations = getObservationStations($conn);

function missingObservations($currentObservations, $monthSelect) {
    $retArr = array();
    $thisYear = intval(date("Y"));
    $maxDate = intval(date("Ymd"));
    for ($i = MINYEAR; $i <= $thisYear; $i++) {
        $chkStartDate = DateTime::createFromFormat("Ymd", "{$i}{$monthSelect}01");
        $daysInMonth = date("t", $chkStartDate->getTimestamp());
        for ($j = 1; $j <= $daysInMonth; $j++) {
            $dayStr = ($j < 10) ? "0".$j : $j;
            $chkStr = "{$i}{$monthSelect}{$dayStr}";
            if (!in_array($chkStr, $currentObservations) && intval($chkStr) < $maxDate) array_push($retArr, $chkStr);
        }
    }

    return $retArr;
}

function isNext($cur, $next) {
    $chkStartDate = DateTime::createFromFormat("Ymd", $cur);
    return date("Ymd", strtotime("tomorrow", $chkStartDate->getTimestamp())) == $next;
}

function missingObservationsAsSeries($missingObservations) {
    $retArr = array();

    $startOfSerie = "";
    $endOfSerie = "";

    for ($i = 0; $i < count($missingObservations); $i++) {
        if ($startOfSerie == "") {
            $startOfSerie = $missingObservations[$i];
        }

        if ($startOfSerie != "") {
            if ($i + 1 == count($missingObservations) || !isNext($missingObservations[$i], $missingObservations[$i+1])) {
                $endOfSerie = $missingObservations[$i];
            }
        }

        if ($startOfSerie != "" && $endOfSerie != "") {
            array_push($retArr, [$startOfSerie, $endOfSerie]);
            $startOfSerie = "";
            $endOfSerie = "";
        }
    }

    return $retArr;
}

function serieDateToQueryParam($date) {
    return substr($date, 0, 4)."-".substr($date, 4, 2)."-".substr($date, 6, 2);
}

function addValuesToDatabase($conn, $locationSelect, $values) {
    foreach ($values as $ind => $value) {
        if (!$conn->query("CALL addObservation (@Oid, ".$locationSelect.", '".$value[0]."', ".$value[1].", ".$value[2].", ".$value[3].");")) {
            error_log($conn->error);
        }
    }
}

function parseTempValues($tempValues) {
    $retArr = array();
    $rows = explode("\n", $tempValues);
    foreach ($rows as $ind => $row) {
        $temps = explode(" ", trim($row));
        if (count($temps) == 3) {
            array_push($retArr, [$temps[0], $temps[1], $temps[2]]);
        }
    }
    return $retArr;
}

function parseDateValues($dateValues) {
    $retArr = array();
    $rows = explode("\n", $dateValues);
    foreach ($rows as $ind => $row) {
        $temps = explode(" ", trim($row));
        if (count($temps) == 4) {
            array_push($retArr, date("Ymd", $temps[3]));
        }
    }
    return $retArr;
}

function combineFMIArrays($tempArr, $dateArr) {
    $retArr = array();
    for ($i = 0; $i < count($tempArr); $i++) {
        array_push($retArr, [$dateArr[$i], $tempArr[$i][0], $tempArr[$i][1], $tempArr[$i][2]]);
    }
    return $retArr;
}

function parseValues($tmpValues) {
    $tempArr = array();
    $dateArr = array();
    $tmpElements = $tmpValues->getElementsByTagNameNS('http://www.opengis.net/gml/3.2', 'doubleOrNilReasonTupleList');
    if ($tmpElements->length == 1) $tempArr = parseTempValues($tmpElements->item(0)->nodeValue);

    $dateElements = $tmpValues->getElementsByTagNameNS('http://www.opengis.net/gmlcov/1.0', 'positions');
    if ($dateElements->length == 1) $dateArr = parseDateValues($dateElements->item(0)->nodeValue);

    if (count($tempArr) == count($dateArr) && count($tempArr) > 0) {
        return combineFMIArrays($tempArr, $dateArr);
    }
    return array();
}


function getTemperatures($conn, $missingObservationsAsSeries, $location, $locationSelect) {
    // exit(print_r($missingObservationsAsSeries));
    foreach ($missingObservationsAsSeries as $key => $serie) {
        $start = serieDateToQueryParam($serie[0]);
        $end = serieDateToQueryParam($serie[1]);
        $query_param = "parameters=tmin,tmax,tday&starttime={$start}T00:00:00Z&endtime={$end}T00:00:00Z&place=".$location;
        $tmpData = download_remote_file_with_curl(SERVER_URL, STORED_QUERY_AVG_OBSERVATION, $query_param);
        $parsedValues = parseValues($tmpData);
        //exit(print_r($parsedValues));
        if (count($parsedValues) > 0) {
            addValuesToDatabase($conn, $locationSelect, $parsedValues);
        }
    }
}

$monthSelect = isset($_GET["mon"]) ? $_GET["mon"] : date("m");

$locationSelect = isset($_GET["loc"]) ? $_GET["loc"] : "";
if ($locationSelect != "") {
    $currentObservations = getObservations($conn, $locationSelect);
    $missingObservations = missingObservations($currentObservations, $monthSelect);
    $missingObservationsAsSeries = missingObservationsAsSeries($missingObservations);
    getTemperatures($conn, $missingObservationsAsSeries, $obsStations[$locationSelect], $locationSelect);
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
