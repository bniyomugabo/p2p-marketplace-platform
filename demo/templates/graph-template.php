<?php
// templates/graph-template.php
// Reusable graph template - JavaScript class is now in assets/js/graph.js
?>

<div class="graph-container mb-4">
    <div class="card shadow">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-chart-line me-2"></i>
                    <span id="graphTitle_<?php echo $canvasId; ?>"><?php echo htmlspecialchars($graphTitle ?? 'Sales Overview'); ?></span>
                </h6>
                
                <!-- Period Selector -->
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary period-btn" data-graph="<?php echo $canvasId; ?>" data-period="daily">
                        <i class="fas fa-calendar-day me-1"></i>Daily
                    </button>
                    <button type="button" class="btn btn-outline-secondary period-btn active" data-graph="<?php echo $canvasId; ?>" data-period="weekly">
                        <i class="fas fa-calendar-week me-1"></i>Weekly
                    </button>
                    <button type="button" class="btn btn-outline-secondary period-btn" data-graph="<?php echo $canvasId; ?>" data-period="monthly">
                        <i class="fas fa-calendar-alt me-1"></i>Monthly
                    </button>
                    <button type="button" class="btn btn-outline-secondary period-btn" data-graph="<?php echo $canvasId; ?>" data-period="yearly">
                        <i class="fas fa-calendar-year me-1"></i>Yearly
                    </button>
                </div>
                
                <!-- Export Button -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="exportGraphAsImage('<?php echo $canvasId; ?>')">Export as Image</a></li>
                        <li><a class="dropdown-item" href="#" onclick="exportGraphData('<?php echo $canvasId; ?>')">Export Data (CSV)</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="card-body">
            <!-- Chart Canvas -->
            <div style="position: relative; height: 350px;">
                <canvas id="<?php echo $canvasId; ?>"></canvas>
            </div>
            
            <!-- Loading Indicator -->
            <div id="loading_<?php echo $canvasId; ?>" class="text-center py-5" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading graph data...</p>
            </div>
        </div>
        
        <!-- Summary Stats -->
        <div class="card-footer bg-white">
            <div class="row text-center">
                <div class="col-md-4">
                    <div class="small text-muted">Total Amount</div>
                    <div class="h5 mb-0 fw-bold" id="statTotal_<?php echo $canvasId; ?>">0</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Average</div>
                    <div class="h5 mb-0 fw-bold" id="statAverage_<?php echo $canvasId; ?>">0</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Total Count</div>
                    <div class="h5 mb-0 fw-bold" id="statCount_<?php echo $canvasId; ?>">0</div>
                </div>
            </div>
        </div>
    </div>
</div>