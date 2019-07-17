<?php
/**
 * Merges and corrects exported Jira issue CSVs into safe-to-import CSVs.
 *
 * Yea, I know... why is this even necessary?
 * Long story short: Jira CSV import is totally incompatible with Jira CSV exports.
 *
 * To use:
 *  - Get all the CSV exports from jira (named like "JIRA (1).csv") and dump them into this folder.
 *  - Then run `php run.php`
 *  - Try importing all_projects.csv
 *  - If that doesn't work, import each project_ file independently.
 *
 * Flaws this doesn't fix:
 *  - The Jira CSV exporter seems to fail when there's encoded JSON in the data/comments/summary.
 *  - The Jira CSV exporter doesn't use the same date format for all fields.
 *  - The Jira CSV importer doesn't link epics when they are going in to a Next Gen project.
 *  - The Jira CSV importer fails to create issue types for a Next Gen project, if those issue types are pre-existing.
 *
 * This is a linear mess because I needed this in a big hurry, and didn't expect it to be so complicated.
 * May make a popper codebase for this in the future. I'm putting this in a repo for future-me that may need it.
 *
 * (don't judge me bro)
 */

set_time_limit(0);
ini_set('memory_limit', -1);

$iterator = new DirectoryIterator(dirname(__FILE__));
$files    = [];
foreach ($iterator as $item) {
    if (!$item->isDot() && 'csv' === pathinfo($item->getFilename(), PATHINFO_EXTENSION) && 'JIRA ' === substr(
            $item->getFilename(),
            0,
            5
        )) {
        $files[] = $item->getFilename();
    }
}
sort($files);

// Discern all headers, counting duplicates (per Jira spec).
$allHeaderCounts = $fileColumns = [];
foreach ($files as $file) {
    if (($handle = fopen($file, 'r')) !== false) {
        $fileHeaders = [];
        if (($data = fgetcsv($handle)) !== false) {
            foreach ($data as $i => $column) {
                if (!isset($fileHeaders[$column])) {
                    $fileHeaders[$column] = 0;
                }
                $fileHeaders[$column]++;

                if (!isset($fileColumns[$file])) {
                    $fileColumns[$file] = [];
                }
                $fileColumns[$file][$i] = $column;
            }
        }
        // Update global column usage counts.
        foreach ($fileHeaders as $column => $count) {
            if (!isset($allHeaderCounts[$column])) {
                $allHeaderCounts[$column] = $count;
            } else {
                $allHeaderCounts[$column] = max($allHeaderCounts[$column], $count);
            }
        }
        fclose($handle);
    }
}

// Create a new index of all columns merged.
$allHeaders = [];
foreach ($allHeaderCounts as $column => $count) {
    for ($i = 0; $i < $count; $i++) {
        $allHeaders[] = $column;
    }
}

// Update the map by combining all columns.
// $fileColumns is now a file name to column ID in the file mapped to a column ID in our dataset.
foreach ($fileColumns as $file => $columns) {
    $headers = $allHeaders;
    foreach ($columns as $i => $column) {
        $key = array_search($column, $headers, true);
        unset($headers[$key]);
        $fileColumns[$file][$i] = $key;
    }
}

// Now get the data from the files merging to one big file by headers.
$mergedRows = [];
$emptyRow   = array_fill(0, count($allHeaders), '');
foreach ($files as $file) {
    if (($handle = fopen($file, 'r')) !== false) {
        if (($data = fgetcsv($handle)) !== false) {
            // Ignore the first line.
        }
        while (($data = fgetcsv($handle)) !== false) {
            $row = $emptyRow;
            foreach ($fileColumns[$file] as $i => $mergedIndex) {
                if (!empty($data[$i])) {
                    $row[$mergedIndex] = $data[$i];
                }
            }
            $mergedRows[] = $row;
        }
        fclose($handle);
    }
}

// Get the names of all Epics.
$epicLinkIndex = array_search('Custom field (Epic Link)', $allHeaders, true);
$epicNameIndex = array_search('Custom field (Epic Name)', $allHeaders, true);
// $summaryIndex = array_search('Summary', $allHeaders, true);
$issueKeyIndex  = array_search('Issue key', $allHeaders, true);
$issueTypeIndex = array_search('Issue Type', $allHeaders, true);
$linkedToEpics  = 0;
$summaryIndex   = array_search('Summary', $allHeaders, true);
if ($issueKeyIndex && $epicLinkIndex !== false && $epicNameIndex !== false) {
    echo "Epics: ";
    $epicNamesByKey = [];
    foreach ($mergedRows as $mergedRow) {
        if (!empty($mergedRow[$epicNameIndex]) && !empty($mergedRow[$issueKeyIndex])) {
            // This is an epic.
            // echo "Epic Name: ".$mergedRow[$issueKeyIndex].PHP_EOL;
            echo $mergedRow[$issueKeyIndex].',';
            // showRow($mergedRow, $allHeaders);
            $epicNamesByKey[$mergedRow[$issueKeyIndex]] = $mergedRow[$epicNameIndex];
        } else {
            if ($issueTypeIndex && !empty($mergedRow[$issueTypeIndex]) && $mergedRow[$issueTypeIndex] === 'Epic') {
                echo $mergedRow[$issueKeyIndex].',';
                if (empty($mergedRow[$epicNameIndex]) && $summaryIndex && !empty($mergedRow[$summaryIndex])) {
                    // This epic has no name, so fall back to the summary... and pray that works.
                    $mergedRow[$epicNameIndex]                  = $mergedRow[$summaryIndex];
                    $epicNamesByKey[$mergedRow[$issueKeyIndex]] = $mergedRow[$summaryIndex];
                }
            }
        }
    }

    // Replace the Epic Link field with the Epic names so that Jira will import this data correctly.
    foreach ($mergedRows as $i => $mergedRow) {
        if (!empty($mergedRow[$epicLinkIndex]) && !empty($epicNamesByKey[$mergedRow[$epicLinkIndex]])) {
            $mergedRows[$i][$epicLinkIndex] = $epicNamesByKey[$mergedRow[$epicLinkIndex]];
            $linkedToEpics++;
        }
    }
    if ($linkedToEpics) {
        echo PHP_EOL."Linked $linkedToEpics issues to epics.".PHP_EOL;
    }
}

// @todo /////////////////////////////////////////////////////////////////////////////////
// @todo Find those with the Todo status that should be Done
// $statusIndex = array_search('Status', $allHeaders, true);
// $projectKeyIndex = array_search('Project key', $allHeaders, true);
// if ($statusIndex && $projectKeyIndex) {
//     echo "Issues To Do (that should be To Do) in CPA: ";
//     foreach ($mergedRows as $i => $mergedRow) {
//         if (
//             !empty($mergedRow[$projectKeyIndex])
//             && $mergedRow[$projectKeyIndex] === 'PROJECTKEYHERE'
//             && $mergedRow[$statusIndex] === 'Done'
//         ) {
//             echo $mergedRow[$issueKeyIndex].',';
//         }
//     }
//     echo PHP_EOL;
// }
// @todo /////////////////////////////////////////////////////////////////////////////////

// Correct time to resolution to seconds
$timeToResolutionIndex = array_search('Custom field (Time to resolution)', $allHeaders, true);
if ($timeToResolutionIndex) {
    foreach ($mergedRows as $i => $mergedRow) {
        if (!empty($mergedRow[$timeToResolutionIndex])) {
            sscanf($mergedRow[$timeToResolutionIndex], "%d:%d:%d", $hours, $minutes, $seconds);
            $mergedRows[$i][$timeToResolutionIndex] = isset($hours) ? (($hours * 3600) + ($minutes * 60) + $seconds) : ($minutes * 60) + $seconds;
        }
    }
}

// Force UTF-8
foreach ($allHeaders as $i => $header) {
    $allHeaders[$i] = iconv("UTF-8", "ISO-8859-1//IGNORE", $header);
}
unset($header);
foreach ($mergedRows as $i => $mergedRow) {
    foreach ($mergedRow as $k => $value) {
        $mergedRows[$i][$k] = iconv("UTF-8", "ISO-8859-1//IGNORE", $value);
    }
}
unset($mergedRow);

// Remove completely empty rows.
$notEmptyMergedRows = [];
foreach ($mergedRows as $i => $mergedRow) {
    foreach ($mergedRow as $value) {
        if (!empty(trim($value))) {
            $notEmptyMergedRows[] = $mergedRows[$i];
            break;
        }
    }
}
$empties = count($mergedRows) - count($notEmptyMergedRows);
if ($empties) {
    echo "Removed $empties empty rows.".PHP_EOL;
    $mergedRows = $notEmptyMergedRows;
}

// Remove rows that do not have an issue key.
$issueKeyIndex   = array_search('Issue key', $allHeaders, true);
$lastKey         = 'Unknown';
$cleanMergedRows = [];
$garbled         = 0;
foreach ($mergedRows as $i => $mergedRow) {
    if (!preg_match('/^[A-Z]{1,10}-?[A-Z0-9]+-\d+$/', $mergedRow[$issueKeyIndex])) {
        // echo implode("", $mergedRow).PHP_EOL;
        // echo "Garbled row will be excluded: ".$i." likely from ".$lastKey.PHP_EOL;
        // echo "Invalid key ".$mergedRow[$issueKeyIndex].PHP_EOL;
        $garbled++;
    } else {
        $lastKey           = $mergedRow[$issueKeyIndex];
        $cleanMergedRows[] = $mergedRows[$i];
    }
}
echo "Removed $garbled rows that were garbled.".PHP_EOL;
$mergedRows = $cleanMergedRows;

// @todo - Set all date formats to "dd/MMM/yy h:mm a" which is the default for the Jira importer?


$files                     = [];
$files['all_projects.csv'] = $mergedRows;

// Split file into projects.
$projectKeyIndex = array_search('Project key', $allHeaders, true);
if ($projectKeyIndex) {
    foreach ($mergedRows as $i => $mergedRow) {
        if (!empty($mergedRow[$projectKeyIndex])) {
            $filename = 'project_'.$mergedRow[$projectKeyIndex].'.csv';
            if (!isset($files[$filename])) {
                $files[$filename] = [];
            }
            $files[$filename][] = $mergedRow;
        }
    }
}

// Actually output the files now.
foreach ($files as $filename => $mergedRows) {
    $outputFile = fopen($filename, 'w');
    fputs($outputFile, $bom = (chr(0xEF).chr(0xBB).chr(0xBF)));
    fput($outputFile, $allHeaders, ',', '"', "\0");
    foreach ($mergedRows as $mergedRow) {
        fput($outputFile, $mergedRow, ',', '"', "\0");
    }
    fclose($outputFile);

    echo "File $filename created with ".(count($mergedRows) + 1)." rows".PHP_EOL;

    // The rest does something like a CSV checksum to make sure we are encoded correctly.
    $verifiedRows = [];
    if (($handle = fopen($filename, 'r')) !== false) {
        if (($data = fget($handle)) !== false) {
            // Ignore the first line.
        }
        while (($data = fget($handle)) !== false) {
            $verifiedRows[] = $data;
        }
        fclose($handle);
    }
    if (count($verifiedRows) !== count($mergedRows)) {
        echo "File $filename row count does not match. Original: $mergedRows Result: $verifiedRows".PHP_EOL;
    }
    foreach ($mergedRows as $i => $mergedRow) {
        if ($mergedRow != $verifiedRows[$i]) {
            echo "Row discrepancy with original row ".$i.PHP_EOL;
            // echo var_export($verifiedRows[$i], true).PHP_EOL;;
            // echo PHP_EOL."---------------------- should be ----------------------".PHP_EOL;
            // echo var_export($mergedRow, true).PHP_EOL;;
            // echo PHP_EOL."----------------------  diff ----------------------".PHP_EOL;
            var_export(array_diff($mergedRow, $verifiedRows[$i]));
            exit();
        }
    }
}


function fget($handle, $length = 0, $delimiter = ',', $enclosure = '"', $escape = "\0")
{
    return fgetcsv($handle, $length, $delimiter, $enclosure, $escape);
}

function fput($handle, $fields = [], $delimter = ',', $enclosure = '"', $escape = "\0")
{
    return fputcsv($handle, $fields, $delimter, $enclosure, $escape);
}

function showRow($mergedRow, $allHeaders)
{
    $output = [];
    foreach ($allHeaders as $i => $header) {
        $output[$header] = isset($mergedRow[$i]) ? $mergedRow[$i] : '';
    }
    die(var_export($output, true));
}