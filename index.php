<?php
include_once 'common_functions.php';
include_once 'db_conn.php';

DEFINE("SERVER_URL", "https://opendata.fmi.fi/wfs");
DEFINE("STORED_QUERY_AVG_OBSERVATION", "fmi::observations::weather::daily::multipointcoverage");

DEFINE("MINYEAR", 1960);

$colorMapping = array();

$conn = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$obsStations = getObservationStations($conn);

$showAll = isset($_GET["all"]) ? boolval($_GET["all"]) : false;

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
    $countedValues = getObservationValues($conn, $locationSelect, $monthSelect, $showAll);
    // exit(print_r($countedValues));
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
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="showAll" value="<?= $monthVal ?>" <?= $showAll ? "checked" : "" ?>>
            <label class="form-check-label" for="showAll">
                Show yearly average values
            </label>
        </div>
    </div>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
    <script src="./js/temphistory.js"></script>
    <script type="text/javascript">
        function drawTempChart() {
            var data = new google.visualization.DataTable();
            data.addColumn('string', 'Date');
            data.addColumn('number', 'Avg');
            <?= addColumns($showAll) ?>

            data.addRows([
                <?= addRows($countedValues, $showAll) ?>
            ]);

            var options = {
                animation: {
                    startup: true,
                    duration: 5000,
                    easing: "inAndOut"
                },
                pointSize: <?= $showAll ? "1" : "7" ?>,
                height: 800,
                series: {
                    <?= addSeries($showAll) ?>
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
