$(function() {
    $("#locationSelect").on("change", function() {
        handleGo(0);
        // var selection = $('#locationSelect option:selected')[0].value;
        // window.location = "index.php?loc="+selection;
    });
    $(".monthButton").on("click", function() {
        handleGo(this.value);
        // var selection = $('#locationSelect option:selected')[0].value;
        // window.location = "index.php?loc="+selection+"&mon="+this.value;
    });
    $("#showAll").on("click", function() {
        handleGo(0);
    });
});

function handleGo(mon) {
    var selection = $('#locationSelect option:selected')[0].value;
    if (selection > 0) {
        var monthVal = mon > 0 ? mon : $('#showAll').val();
        var showAll = $('#showAll').prop('checked');
        var showAllStr = showAll ? "1" : "0" ;
        window.location = "index.php?loc="+selection+"&mon="+monthVal+"&all="+showAllStr;
    }
}

Date.prototype.mmdd = function() {
    var mm = this.getMonth() + 1; // getMonth() is zero-based
    var dd = this.getDate();
  
    return [(mm>9 ? '' : '0') + mm,
            (dd>9 ? '' : '0') + dd
           ].join('');
};

function getColor(startYear, endYear, curYear) {
    console.log(startYear);
    console.log(curYear);
    console.log(curYear-startYear);
    return numberToColor(curYear-startYear, parseInt((endYear-startYear)*1.4, 10));
}

function drawChart(startYear, endYear) {
    thisMonth.forEach(function (data) {
        console.log(getColor(startYear, endYear, data.minYear));
    });
}


async function getTemps() {
    for (var i = 1; i <= 30; i++) {
        var iStr = i;
        if (i < 10) iStr = "0"+iStr;
        var arrStr = "11"+iStr;
        thisMonth[arrStr] = { min: null, max: null, avg: 0, count: 0, minYear: null, maxYear: null};
    }
    var minYear = 2000;
    var maxYear = 2020;
    for (var i = minYear; i <= maxYear; i++) await getAvgTemp(SERVER_URL, i);
    console.log(thisMonth);
    drawChart(minYear, maxYear)
}

function parseTemps(data) {
    data.locations.forEach(function (location) {
        location.data.tday.timeValuePairs.forEach(function (valuePair) {
            var dateStr = new Date(valuePair.time).mmdd();
            var value = parseFloat(valuePair.value);
            if (thisMonth[dateStr]) {
                thisMonth[dateStr].avg = ((thisMonth[dateStr].avg * thisMonth[dateStr].count) + value) / (thisMonth[dateStr].count + 1);
                thisMonth[dateStr].count++;
            }
        });
        location.data.tmin.timeValuePairs.forEach(function (valuePair) {
            var dateStr = new Date(valuePair.time).mmdd();
            var value = parseFloat(valuePair.value);
            if (thisMonth[dateStr]) {
                if (thisMonth[dateStr].min == null || thisMonth[dateStr].min > value) {
                    thisMonth[dateStr].min = value;
                    thisMonth[dateStr].minYear = new Date(valuePair.time).getFullYear();
                }
            }
        });
        location.data.tmax.timeValuePairs.forEach(function (valuePair) {
            var dateStr = new Date(valuePair.time).mmdd();
            var value = parseFloat(valuePair.value);
            if (thisMonth[dateStr]) {
                if (thisMonth[dateStr].max == null || thisMonth[dateStr].max < value) {
                    thisMonth[dateStr].max = value;
                    thisMonth[dateStr].maxYear = new Date(valuePair.time).getFullYear();
                }
            }
        });
    });
}

async function getAvgTemp(url, year) {
    var dateStartStr = "" + year + "-11-01T00:00:00Z";
    var dateEndStr = "" + year + "-11-30T00:00:00Z";
    Metolib.WfsRequestParser.getData({
        url : url,
        storedQueryId : STORED_QUERY_AVG_OBSERVATION,
        requestParameter : "tday,tmin,tmax",
        // Integer values are used to init dates for older browsers.
        // (new Date("2013-05-10T08:00:00Z")).getTime()
        // (new Date("2013-05-12T10:00:00Z")).getTime()
        begin : (new Date(dateStartStr)).getTime(),
        end : (new Date(dateEndStr)).getTime(),
        // begin : new Date(1368172800000),
        // end : new Date(1368352800000),
        sites : "Kuopio",
        callback : function(data, errors) {
            // Handle the data and errors object in a way you choose.
            // Here, we delegate the content for a separate handler function.
            // See parser documentation from source code comments for more details.
            //handleCallback(data, errors, "Stations");
            parseTemps(data);
        }
    });

    return new Promise(resolve => {
        setTimeout(resolve, (2500));
    });
}

function testHkiTd(url) {
    // See API documentation and comments from parser source code of
    // Metolib.WfsRequestParser.getData function for the description
    // of function options parameter object and for the callback parameters objects structures.
    Metolib.WfsRequestParser.getData({
        url : url,
        storedQueryId : STORED_QUERY_OBSERVATION,
        requestParameter : "td",
        // Integer values are used to init dates for older browsers.
        // (new Date("2013-05-10T08:00:00Z")).getTime()
        // (new Date("2013-05-12T10:00:00Z")).getTime()
        begin : new Date(1368172800000),
        end : new Date(1368352800000),
        timestep : 60 * 60 * 1000,
        sites : "Helsinki",
        callback : function(data, errors) {
            // Handle the data and errors object in a way you choose.
            // Here, we delegate the content for a separate handler function.
            // See parser documentation from source code comments for more details.
            handleCallback(data, errors, "Helsinki td");
        }
    });
}

/**
 * Handle parser results in this callback function.
 *
 * Append result strings to the UI.
 *
 * @param {Object} data Parsed data.
 * @param {Object} errors Parser errors.
 * @param {String} test case name.
 */
function handleCallback(data, errors, caseName) {
    var results = jQuery("#results");
    results.append("<h2>" + caseName + "</h2>");
    if (data) {
        results.append("<h3>Data object</h3>");
        recursiveBrowse(results, data, "");
    }
    if (errors) {
        results.append("<h3>Errors object</h3>");
        recursiveBrowse(results, errors, "");
    }
}

/**
 * This function recursively browses the given {data} structure and appends the content as text
 * to the {container} element.
 *
 * @param {Element} container Content is appended as a text here.
 * @param {Object|Array|String|etc} data Content that is browsed through recursively.
 * @param {String} indentStr Indentation string of the previous recursion level.
 */
function recursiveBrowse(container, data, indentStr) {
    if (_.isArray(data) || _.isObject(data)) {
        // Browse all the child items of the array or object.
        indentStr += ">";
        _.each(data, function(value, key) {
            container.append("<br>" + indentStr + " [" + key + "]");
            recursiveBrowse(container, value, indentStr);
        });

    } else {
        // This is a leaf. So, just append it after its container array or object.
        container.append(" > " + data);
    }
}
