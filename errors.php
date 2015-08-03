<?php
ini_set("display_errors", "1");
error_reporting(E_ALL);

require_once 'meekrodb.2.3.class.php';
require_once 'conf.php';

DB::$user = MIGRATION_HELPER_DB_USER;
DB::$password = MIGRATION_HELPER_DB_PASSWORD;
DB::$dbName = MIGRATION_HELPER_DB_SCHEMA;
DB::$host = MIGRATION_HELPER_DB_HOST;

$table = MIGRATION_HELPER_DB_TABLE_PREFIX . "_problems";
$issues = DB::query("SELECT * FROM " . $table);
?>

<html>
<head>
    <style type="text/css">
        body {
            font-family: "Helvetica Neue", Helvetica, Arial;
            font-size: 14px;
            line-height: 20px;
            font-weight: 400;
            color: #3b3b3b;
            background: #2e2e2e;
        }

        h1 {
            padding: 20px;
            text-align: center;
            color: #7D4CAD;
            text-shadow: -2px -2px 1px rgba(88, 53, 121, 0.5);
        }

        h1 .underline {
            text-decoration: none;
            padding: 1px;
            border-bottom: solid 4px #7D4CAD;
        }

        .wrapper {
            margin: 0 auto;
            padding: 0 50px;
        }

        .table {
            margin: 0 0 40px 0;
            width: 100%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            display: table;
        }

        .row {
            display: table-row;
            background: #f6f6f6;
        }

        .row:nth-of-type(odd) {
            background: #e9e9e9;
        }

        .row.header {
            font-weight: 900;
            color: #ffffff;
            background: #7D4CAD;
            text-shadow: 2px 2px 1px rgba(88, 53, 121, 0.5);
        }

        .cell {
            padding: 4px 12px;
            display: table-cell;
        }
    </style>
</head>
    <body>
        <h1><span class="underline">SMF to OxWall Migration Issues:</span></h1>
        <div class="wrapper">
            <div class="table">

                <div class="row header">
                    <div class="cell">
                        #
                    </div>
                    <div class="cell">
                        Object Type
                    </div>
                    <div class="cell">
                        Object ID
                    </div>
                    <div class="cell">
                        Error Message
                    </div>
                </div>
                <?php foreach($issues as $issue): ?>
                <div class="row">
                    <div class="cell">
                        <?php echo $issue["id"]; ?>
                    </div>
                    <div class="cell">
                        <?php echo $issue["object_type"]; ?>
                    </div>
                    <div class="cell">
                        <?php echo $issue["object_id"]; ?>
                    </div>
                    <div class="cell">
                        <?php echo $issue["error_message"]; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </body>
</html>
