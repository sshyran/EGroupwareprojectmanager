<?php
include ("../jpgraph.php");
include ("../jpgraph_bar.php");

$datay=array(12,8,19,3,10,5);

// Create the graph. These two calls are always required
$graph = new Graph(310,200,"auto");	
$graph->img->SetMargin(40,30,20,40);
$graph->SetScale("textlin");
$graph->SetShadow();

// Create a bar pot
$bplot = new BarPlot($datay);
$bplot->SetFillColor("orange");
$graph->Add($bplot);

$graph->title->Set("Example 19");
$graph->xaxis->title->Set("X-title");
$graph->yaxis->title->Set("Y-title");

$graph->title->SetFont(FF_FONT1,FS_BOLD);
$graph->yaxis->title->SetFont(FF_FONT1,FS_BOLD);
$graph->xaxis->title->SetFont(FF_FONT1,FS_BOLD);

// Display the graph
$graph->Stroke();
?>
