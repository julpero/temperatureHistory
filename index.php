<?php
include_once 'common_functions.php';
include_once 'db_conn.php';

DEFINE("SERVER_URL", "https://opendata.fmi.fi/wfs");
DEFINE("STORED_QUERY_AVG_OBSERVATION", "fmi::observations::weather::daily::multipointcoverage");

DEFINE("MINYEAR", 1960);

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$colorMapping = array();

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
        $month = intval(substr($value[0], 4, 2));
        if (!$conn->query("CALL addObservation (@Oid, ".$locationSelect.", '".$value[0]."', ".$month.", ".$value[1].", ".$value[2].", ".$value[3].");")) {
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

function getObservationValues($conn, $locationSelect, $monthSelect) {
    $retArr = array();
    $month = intval($monthSelect);
    if ($result = $conn->query("CALL getObservationValues ({$locationSelect}, {$month});")) {
        while ($row = $result->fetch_assoc()) {
            $datepart = $row["datepart"];
            $minTemp = floatval($row["minTemp"]);
            $maxTemp = floatval($row["maxTemp"]);
            $avgTemp = floatval($row["avgTemp"]);

            $year = substr($datepart, 0, 4);
            $date = substr($datepart, 4, 4);

            if (isset($retArr[$date])) {
                if ($retArr[$date][0] > $minTemp) {
                    $retArr[$date][0] = $minTemp;
                    $retArr[$date][1] = $year;
                }
                if ($retArr[$date][2] < $minTemp) {
                    $retArr[$date][2] = $minTemp;
                    $retArr[$date][3] = $year;
                }
                $retArr[$date][4] = ($retArr[$date][4]*$retArr[$date][5] + $avgTemp) / ($retArr[$date][5] + 1);
                $retArr[$date][5]++;
            } else {
                $retArr[$date] = [$minTemp, $year, $maxTemp, $year, $avgTemp, 1];
            }
        }
        $result->free();
        $conn->next_result();
    } else {
        error_log($conn->error);
    }
    return $retArr;
}

function h2rgb ($initT) {
    $t = $initT < 0 ? $initT + 1 : fmod($initT, 1);

    if ($t < (1 / 6)) {
        return round($t * 1530);
    }
    if ($t < (1 / 2)) {
        return 255;
    }
    if ($t < (2 / 3)) {
        return round(((2 / 3) - $t) * 1530);
    }
    return 0;
}

function map ($hue) {
    return [
        h2rgb($hue - (1 / 3)), // r
        h2rgb($hue), // g
        h2rgb($hue + (1 / 3)) // g
    ];
}

function numberToColor($num, $states) {
    global $colorMapping;

    if (!isset($colorMapping[$states])) {
        $colorMapping[$states] = array();
        for ($i = 0; $i <= $states; $i++) {
            array_push($colorMapping[$states], map($i / $states));
        }
    }
    return $colorMapping[$states][$num];
}

function yearToColor($year, $states) {
    $rgb = numberToColor($year, $states);
    $r = substr("0".dechex($rgb[0]), -2);
    $g = substr("0".dechex($rgb[1]), -2);
    $b = substr("0".dechex($rgb[2]), -2);
    return "#{$r}{$g}{$b}";
}

function addColumns() {
    $retStr = "";
    $thisYear = intval(date("Y"));
    for ($i = MINYEAR; $i <= $thisYear; $i++) {
        $retStr.= "data.addColumn('number', 'min".$i."');\n";
        $retStr.= "data.addColumn('number', 'max".$i."');\n";
    }
    return $retStr;
}

function addSeries() {
    $tmpArr = array();
    array_push($tmpArr, "0:{color: 'black', visibleInLegend: false}\n");
    $thisYear = intval(date("Y"));
    $counter = 1;
    $yearCounter = 0;
    $states = intval(($thisYear - MINYEAR + 1) * 1.4);
    for ($i = MINYEAR; $i <= $thisYear; $i++) {
        $color = yearToColor($yearCounter, $states);
        array_push($tmpArr, $counter.":{color: '{$color}', visibleInLegend: false}\n");
        $counter++;
        array_push($tmpArr, $counter.":{color: '{$color}', visibleInLegend: false}\n");
        $counter++;
        $yearCounter++;
    }
    return implode(",", $tmpArr);
}

function addRows($countedValues) {
    $tmpArr = array();
    $thisYear = intval(date("Y"));
    foreach ($countedValues as $ind => $values) {
        $retStr = "['{$ind}', {$values[4]}";
        for ($i = MINYEAR; $i <= $thisYear; $i++) {
            $retStr.= $values[1] == $i ? ", {$values[0]}" : ", null";
            $retStr.= $values[3] == $i ? ", {$values[2]}" : ", null";
        }
        $retStr.="]\n";
        array_push($tmpArr, $retStr);
    }
    return implode(",", $tmpArr);
}

function printLegend() {
    $tmpArr = array();
    $thisYear = intval(date("Y"));
    $yearCounter = 0;
    $states = intval(($thisYear - MINYEAR + 1) * 1.4);
    for ($i = MINYEAR; $i <= $thisYear; $i++) {
        $color = yearToColor($yearCounter, $states);
        array_push($tmpArr, "<span style=\"background-color: {$color}\">{$i}</span>\n");
        $yearCounter++;
    }
    return implode("", $tmpArr);
}

$monthSelect = isset($_GET["mon"]) ? substr("0".$_GET["mon"], -2) : date("m");
$monthVal = intval($monthSelect);
$prevMonth = $monthVal - 1;
$nextMonth = $monthVal + 1;
$monthName = date('F', mktime(0, 0, 0, $monthVal, 10));

if ($prevMonth < 1) $prevMonth = 12;
if ($nextMonth > 12) $nextMonth = 1;

$locationSelect = isset($_GET["loc"]) ? $_GET["loc"] : "";
if ($locationSelect != "") {
    $currentObservations = getObservations($conn, $locationSelect);
    $missingObservations = missingObservations($currentObservations, $monthSelect);
    $missingObservationsAsSeries = missingObservationsAsSeries($missingObservations);
    getTemperatures($conn, $missingObservationsAsSeries, $obsStations[$locationSelect], $locationSelect);
    $countedValues = getObservationValues($conn, $locationSelect, $monthSelect);
    //exit(print_r($countedValues));
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
        <div class="row">
            <div class="col-1"><button class="btn btn-primary monthButton" id="prevMonthButton" value="<?=$prevMonth?>"><<</button></div>
            <div class="col-10 text-center" id="monthStr"><?=$monthName?> <?= MINYEAR ?>-<?= date("Y") ?></div>
            <div class="col-1"><button class="btn btn-primary monthButton" id="nextMonthButton" value="<?=$nextMonth?>">>></button></div>
        </div>
        <div class="row"><div class="col" id="results"></div></div>
        <div class="row"><div class="col" id="legend"><?= printLegend() ?></div></div>
    </div>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
    <script src="./js/temphistory.js"></script>
    <script type="text/javascript">
        function drawTempChart() {
            var data = new google.visualization.DataTable();
            data.addColumn('string', 'Date');
            data.addColumn('number', 'Avg');
            <?= addColumns() ?>

            data.addRows([
                <?= addRows($countedValues) ?>
            ]);

            var options = {
                series: {
                    <?= addSeries() ?>
                }
            };
            var chart = new google.visualization.ScatterChart(document.getElementById('results'));
            chart.draw(data, options);
        }
        google.charts.load("current", {packages:["corechart"]});
        google.charts.setOnLoadCallback(drawTempChart);
    </script>
</body>
</html>
