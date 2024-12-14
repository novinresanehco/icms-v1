import React, { useState, useEffect } from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';

const MetricsDashboard = () => {
  const [metrics, setMetrics] = useState({});
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const loadInitialData = async () => {
      try {
        const response = await window.fs.readFile('metrics.json', { encoding: 'utf8' });
        const data = JSON.parse(response);
        setMetrics(data);
        setLoading(false);
      } catch (err) {
        setError('Failed to load metrics data');
        setLoading(false);
      }
    };

    loadInitialData();

    // Set up real-time updates
    const updateInterval = setInterval(async () => {
      try {
        const response = await window.fs.readFile('metrics.json', { encoding: 'utf8' });
        const newData = JSON.parse(response);
        setMetrics(newData);
      } catch (err) {
        console.error('Failed to update metrics:', err);
      }
    }, 5000); // Update every 5 seconds

    return () => clearInterval(updateInterval);
  }, []);

  if (loading) {
    return <div className="flex items-center justify-center h-screen">Loading metrics...</div>;
  }

  if (error) {
    return <Alert variant="destructive">{error}</Alert>;
  }

  return (
    <div className="p-4 space-y-4">
      {/* System Health Overview */}
      <Card>
        <CardHeader>System Health</CardHeader>
        <CardContent>
          <div className="grid grid-cols-3 gap-4">
            <HealthMetric
              title="CPU Usage"
              value={metrics.system?.cpu || 0}
              threshold={70}
            />
            <HealthMetric
              title="Memory Usage"
              value={metrics.system?.memory || 0}
              threshold={80}
            />
            <HealthMetric
              title="Response Time"
              value={metrics.performance?.response_time?.avg || 0}
              threshold={200}
            />
          </div>
        </CardContent>
      </Card>

      {/* Performance Trends */}
      <Card>
        <CardHeader>Performance Trends</CardHeader>
        <CardContent className="h-96">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={metrics.history || []}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="timestamp" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Line 
                type="monotone" 
                dataKey="response_time" 
                stroke="#8884d8" 
                name="Response Time" 
              />
              <Line 
                type="monotone" 
                dataKey="throughput" 
                stroke="#82ca9d" 
                name="Throughput" 
              />
            </LineChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Critical Alerts */}
      <Card>
        <CardHeader>Critical Alerts</CardHeader>
        <CardContent>
          <div className="space-y-2">
            {alerts.map((alert, index) => (
              <Alert 
                key={index}
                variant={alert.severity === 'critical' ? 'destructive' : 'warning'}
              >
                {alert.message}
              </Alert>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Security Status */}
      <Card>
        <CardHeader>Security Status</CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4">
            <SecurityMetric
              title="Access Attempts"
              value={metrics.security?.access_attempts || 0}
              type="info"
            />
            <SecurityMetric
              title="Validation Failures"
              value={metrics.security?.validation_failures || 0}
              type="warning"
            />
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

const HealthMetric = ({ title, value, threshold }) => {
  const isWarning = value >= threshold * 0.8;
  const isCritical = value >= threshold;
  const status = isCritical ? 'critical' : isWarning ? 'warning' : 'normal';
  const colors = {
    normal: 'text-green-600',
    warning: 'text-yellow-600',
    critical: 'text-red-600'
  };

  return (
    <div className={`p-4 rounded-lg border ${colors[status]}`}>
      <h3 className="text-lg font-semibold">{title}</h3>
      <p className="text-2xl">{value.toFixed(2)}%</p>
      <p className="text-sm">Threshold: {threshold}%</p>
    </div>
  );
};

const SecurityMetric = ({ title, value, type }) => {
  const colors = {
    info: 'text-blue-600',
    warning: 'text-yellow-600',
    error: 'text-red-600'
  };

  return (
    <div className={`p-4 rounded-lg border ${colors[type]}`}>
      <h3 className="text-lg font-semibold">{title}</h3>
      <p className="text-2xl">{value}</p>
      <p className="text-sm">Last 24 hours</p>
    </div>
  );
};

export default MetricsDashboard;