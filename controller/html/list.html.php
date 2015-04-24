<?php defined("MY_LIST") or die("No direct script access.") ?>
<!DOCTYPE HTML>
<html xmlns="http://www.w3.org/1999/xhtml"  xml:lang="en" lang="en">
  <head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
    <title>Crawling status </title>
    <link rel="stylesheet" href="html/style.css" type="text/css" id="style" media="print, projection, screen" />
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
    <script type="text/javascript" src="html/jquery.tablesorter.min.js"></script>
    <script type="text/javascript" src="html/jquery.tablesorter.widgets.min.js"></script>
<script type="text/javascript">
function myTime(timestamp) {
  //function parses mysql datetime string and returns javascript Date object
  //input has to be in this format: 2007-06-05 15:26:02
  var regex=/^([0-9]{2,4})-([0-1][0-9])-([0-3][0-9]) (?:([0-2][0-9]):([0-5][0-9]):([0-5][0-9]))?$/;
  var parts=timestamp.replace(regex,"$1 $2 $3 $4 $5 $6").split(' ');
  return new Date(Date.UTC(parts[0],parts[1]-1,parts[2],parts[3],parts[4],parts[5])).getTime()/1000;
}
var serverTime = <?php echo time(); ?>;

$(document).ready(function() {
  // call the tablesorter plugin
  $("table").tablesorter({
    widgets: ["zebra", "filter"],

      widgetOptions : {

        // css class applied to the table row containing the filters & the inputs within that row
        filter_cssFilter : 'tablesorter-filter',

          // If there are child rows in the table (rows with class name from "cssChildRow" option)
          // and this option is true and a match is found anywhere in the child row, then it will make that row
          // visible; default is false
          filter_childRows : false,

          // Set this option to true to use the filter to find text from the start of the column
          // So typing in "a" will find "albert" but not "frank", both have a's; default is false
          filter_startsWith : false,

          // Set this option to false to make the searches case sensitive
          filter_ignoreCase : true,

          // Delay in milliseconds before the filter widget starts searching; This option prevents searching for
          // every character while typing and should make searching large tables faster.
          filter_searchDelay : 300,

          // Add select box to 4th column (zero-based index)
          // each option has an associated function that returns a boolean
          // function variables:
          // e = exact text from cell
          // n = normalized value returned by the column parser
          // f = search filter input value
          // i = column index
          filter_functions : {

            // Add select menu to this column
            // set the column value to true, and/or add "filter-select" class name to header
            // 0 : true,
            0 : {
              "Running"      : function(e, n, f, i) { return n > 0 && n < 100; },
                "Done" : function(e, n, f, i) { return n >= 99.9999; },
                "Not done" : function(e, n, f, i) { return n <= 99.9999; },
                "New"     : function(e, n, f, i) { return n <= 0.001; }
},
3 : {
  "last 20 min"   : function(e, n, f, i) { return serverTime-myTime(e) <= 1200; },
    "last 1 h"      : function(e, n, f, i) { return serverTime-myTime(e) <= 3600; },
    "last 12 h"     : function(e, n, f, i) { return serverTime-myTime(e) <= 43200; },
    "last 24 h"     : function(e, n, f, i) { return serverTime-myTime(e) <= 86400; },
    "last 7 days"   : function(e, n, f, i) { return serverTime-myTime(e) <= 604800; },
    "last 14 days"  : function(e, n, f, i) { return serverTime-myTime(e) <= 1209600; },
    "12 h - 24 h"   : function(e, n, f, i) { return serverTime-myTime(e) >= 43200 && serverTime-myTime(e) <= 86400; },
    "1 - 7 days"    : function(e, n, f, i) { return serverTime-myTime(e) >= 86400 && serverTime-myTime(e) <= 604800; },
    "7 - 14 days"   : function(e, n, f, i) { return serverTime-myTime(e) >= 604800 && serverTime-myTime(e) <= 1209600; },
    "> 14 days"     : function(e, n, f, i) { return serverTime-myTime(e) > 1209600; }
},
// Add these options to the select dropdown (numerical comparison example)
// Note that only the normalized (n) value will contain numerical data
// If you use the exact text, you'll need to parse it (parseFloat or parseInt)
4 : {
  "< 1200s (20 min)"      : function(e, n, f, i) { return n < 1200; },
    "20 min - 1 h" : function(e, n, f, i) { return n >= 1200 && n <= 3600; },
    "1 h - 12 h" : function(e, n, f, i) { return n >= 3600 && n <= 43200; },
    "12 h - 24 h " : function(e, n, f, i) { return n >= 43200 && n <= 86400; },
    "1 - 7 days" : function(e, n, f, i) { return n >= 86400 && n <= 604800; },
    "7 - 14 days" : function(e, n, f, i) { return n >= 604800 && n <= 1209600; },
    "> 14 days"     : function(e, n, f, i) { return n > 1209600; }
}
}

},
  initialized : function(table){
    $('select:eq(1)').val($('select:eq(1)>*:eq(3)').val()).change();
},

  sortList: [[0,1],[3,0]]
});
}); </script>
  </head>
  <body>

