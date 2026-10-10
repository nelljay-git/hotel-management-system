<?php

$url = "https://earthquake.phivolcs.dost.gov.ph/";

$html = file_get_contents($url);

libxml_use_internal_errors(true);

$dom = new DOMDocument();
$dom->loadHTML($html);

$xpath = new DOMXPath($dom);

$rows = $xpath->query("//table//tr");

$earthquakes = [];

foreach ($rows as $index => $row) {

    if ($index == 0) continue; // Skip header

    $cols = $row->getElementsByTagName("td");

    if ($cols->length >= 6) {

        $earthquakes[] = [
            "datetime"  => trim($cols->item(0)->textContent),
            "latitude"  => trim($cols->item(1)->textContent),
            "longitude" => trim($cols->item(2)->textContent),
            "depth_km"  => trim($cols->item(3)->textContent),
            "magnitude" => trim($cols->item(4)->textContent),
            "location"  => trim($cols->item(5)->textContent)
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($earthquakes, JSON_PRETTY_PRINT);