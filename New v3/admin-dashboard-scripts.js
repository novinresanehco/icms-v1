// Admin Dashboard Scripts
class AdminDashboard {
    constructor() {
        this.charts = {};
        this.dataRefreshInterval = 30000; // 30 seconds
        this.init();
    }

    init() {
        this.initCharts();
        this.initRealTimeUpdates();
        this.initEventHandlers();
        this.startDataRefresh();
    }

    initCharts() {
        // Performance Chart
        this.charts.performance = new Chart('performanceChart', {
            type: 'line',
            data: this.getPerformanceData(),
            options: this.getChartOptions('System Performance')
        });

        // Resource Usage Chart
        this.charts.resources = new Chart('resourceChart', {
            type: 'bar',
            data: this.getResourceData(),
            options: this.getChartOptions('Resource Usage')
        });

        // Error Rate Chart
        this.charts.errors = new Chart('errorChart', {
            type: 'line',
            data: this.getErrorData(),
            options: this.getChartOptions('Error Rates')
        });
    }

    initRealTimeUpdates() {
        const ws = new WebSocket(WS_URL);
        
        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.updateDashboard(data);
        };

        ws.onerror = (error) => {
            console.error('WebSocket error:', error);
            this.fallbackToPolling();
        };
    }

    initEventHandlers() {
        // Date range selector
        document.getElementById('dateRange').addEventListener('change', (e) => {
            this.updateDateRange(e.target.value);
        });

        // Refresh button
        document.getElementById('refreshData').addEventListener('click', () => {
            this.refreshData();
        });

        // Alert handlers
        document.querySelectorAll('.alert-action').forEach(button => {
            button.addEventListener('click', (e) => {
                this.handleAlertAction(e.target.dataset.action);
            });
        });
    }

    async updateDashboard(data) {
        this.updateCharts(data);
        this.updateMetrics(data);
        this.updateAlerts(data);
        this.updateResourceStatus(data);
    }

    updateCharts(data) {
        Object.keys(this.charts).forEach(chart => {
            if (data[chart]) {
                this.charts[chart].data = data[chart];
                this.charts[chart].update();
            }
        });
    }

    updateMetrics(data) {
        if (data.metrics) {
            Object.keys(data.metrics).forEach(metric => {
                const element = document.getElementById(`metric-${metric}`);
                if (element) {
                    element.textContent = this.formatMetric(
                        data.metrics[metric],
                        metric
                    );
                }
            });
        }
    }

    updateAlerts(data) {
        if (data.alerts) {
            const alertContainer = document.getElementById('alertContainer');
            alertContainer.innerHTML = '';
            
            data.alerts.forEach(alert => {
                alertContainer.appendChild(
                    this.createAlertElement(alert)
                );
            });
        }
    }

    updateResourceStatus(data) {
        if (data.resources) {
            Object.keys(data.resources).forEach(resource => {
                const status = this.getResourceStatus(
                    data.resources[resource]
                );
                this.updateResourceIndicator(resource, status);
            });
        }
    }

    async refreshData() {
        try {
            const response = await fetch('/admin/api/dashboard/data');
            const data = await response.json();
            this.updateDashboard(data);
        } catch (error) {
            console.error('Error refreshing data:', error);
            this.showError('Failed to refresh dashboard data');
        }
    }

    startDataRefresh() {
        setInterval(() => {
            this.refreshData();
        }, this.dataRefreshInterval);
    }

    fallbackToPolling() {
        console.warn('Falling back to polling');
        this.startDataRefresh();
    }

    formatMetric(value, type) {
        switch(type) {
            case 'percentage':
                return `${value.toFixed(2)}%`;
            case 'memory':
                return this.formatBytes(value);
            case 'time':
                return `${value.toFixed(2)}ms`;
            default:
                return value.toString();
        }
    }

    formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let value = bytes;
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex++;
        }

        return `${value.toFixed(2)} ${units[unitIndex]}`;
    }

    getResourceStatus(value) {
        if (value >= 90) return 'critical';
        if (value >= 75) return 'warning';
        return 'normal';
    }

    updateResourceIndicator(resource, status) {
        const indicator = document.getElementById(`${resource}-status`);
        if (indicator) {
            indicator.className = `status-indicator ${status}`;
        }
    }

    createAlertElement(alert) {
        const element = document.createElement('div');
        element.className = `alert alert-${alert.severity}`;
        element.innerHTML = `
            <h4>${alert.title}</h4>
            <p>${alert.message}</p>
            <div class="alert-actions">
                <button class="btn btn-sm btn-primary alert-action" 
                        data-action="acknowledge" 
                        data-alert-id="${alert.id}">
                    Acknowledge
                </button>
            </div>
        `;
        return element;
    }

    async handleAlertAction(action) {
        try {
            const response = await fetch('/admin/api/alerts/action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.csrfToken
                },
                body: JSON.stringify({ action })
            });

            if (!response.ok) throw new Error('Failed to handle alert action');
            
            await this.refreshData();
        } catch (error) {
            console.error('Error handling alert action:', error);
            this.showError('Failed to process alert action');
        }
    }

    showError(message) {
        const notification = new Notification('error', message);
        notification.show();
    }

    getChartOptions(title) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            title: { text: title },
            legend: { position: 'bottom' },
            scales: {
                yAxes: [{
                    ticks: { beginAtZero: true }
                }]
            },
            tooltips: {
                enabled: true,
                intersect: false,
                mode: 'index'
            }
        };
    }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.adminDashboard = new AdminDashboard();
});
