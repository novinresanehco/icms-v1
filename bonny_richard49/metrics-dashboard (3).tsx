import React, { useState, useEffect } from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Card, CardHeader, CardContent } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { Loader2 } from 'lucide-react';

// Initial metrics state with simulated data
const initialMetrics = {
  system: {
    cpu: 45.5,
    memory: 62.3,
    connections: { active: 125, idle: 25, total: 150 }
  },
  performance: {
    response_time: { avg: 156, max: 289, min: 45, p95: 245 },
    throughput: {
      requests_per_second: 850,
      bytes_per_second: 1024000,
      concurrent_requests: 75
    }
  },
  security: {
    access_attempts: 1250,
    validation_failures: 15,
    threat_level: 'low'
  },
  history: []
};

// Generate 24 hours of sample historical data
const generateHistoricalData = () => {
  return Array.from({ length: 24 }, (_, i) => ({
    timestamp: new Date(Date.now() - (23 - i) * 3600000).toISOString(),
    response_time: 150 + Math.random() * 100,
    throughput: 800 + Math.random() * 200
  }));
};

const MetricsDashboard = () => {
  const [metrics, setMetrics] = useState(initialMetrics);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Simulate initial data load
    const loadData = () => {
      setMetrics(prev => ({
        ...prev,
        history: generateHistoricalData()
      }));
      setLoading(false);
    };

    loadData();

    // Simulate real-time updates
    const updateInterval = setInterval(() => {
      setMetrics(prev => ({
        ...prev,
        system: {
          ...prev.system,
          cpu: Math.max(0, Math.min(100, prev.system.cpu + (Math.random() * 10 - 5))),
          memory: Math.max(0, Math.min(100, prev.system.memory + (Math.random() * 10 - 5)))
        },
        history: generateHistoricalData()
      }));
    }, 5000);

    return () => clearInterval(updateInterval);
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <Loader2 className="h-8 w-8 animate-spin mr-2" />
        <span>Loading metrics...</span>
      </div>
    );
  }

  return (
    <div className="p-4 space-y-4">
      {/* System Health */}
      <Card>
        <CardHeader className="font-bold">System Health</CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <HealthMetric
              title="CPU Usage"
              value={metrics.system.cpu}
              threshold={70}
              unit="%"
            />
            <HealthMetric
              title="Memory Usage"
              value={metrics.system.memory}
              threshold={80}
              unit="%"
            />
            <HealthMetric
              title="Response Time"
              value={metrics.performance.response_time.avg}
              threshold={200}
              unit="ms"
            />
          </div>
        </CardContent>
      </Card>

      {/* Performance Chart */}
      <Card>
        <CardHeader className="font-bold">Performance Trends</CardHeader>
        <CardContent className="h-96">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={metrics.history}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis 
                dataKey="timestamp" 
                tickFormatter={(timestamp) => new Date(timestamp).toLocaleTimeString()}
              />
              <YAxis yAxisId="left" orientation="left" stroke="#8884d8" />
              <YAxis yAxisId="right" orientation="right" stroke="#82ca9d" />
              <Tooltip 
                labelFormatter={(label) => new Date(label).toLocaleString()}
                contentStyle={{ backgroundColor: 'rgba(255, 255, 255, 0.95)' }}
              />
              <Legend />
              <Line 
                yAxisId="left"
                type="monotone" 
                dataKey="response_time" 
                stroke="#8884d8" 
                name="Response Time (ms)" 
                dot={false}
              />
              <Line 
                yAxisId="right"
                type="monotone" 
                dataKey="throughput" 
                stroke="#82ca9d" 
                name="Throughput (req/s)" 
                dot={false}
              />
            </LineChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Security Stats */}
      <Card>
        <CardHeader className="font-bold">Security Status</CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <SecurityMetric
              title="Access Attempts"
              value={metrics.security.access_attempts}
              type="info"
            />
            <SecurityMetric
              title="Validation Failures"
              value={metrics.security.validation_failures}
              type="warning"
            />
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

const HealthMetric = ({ title, value, threshold, unit }) => {
  const isWarning = value >= threshold * 0.8;
  const isCritical = value >= threshold;
  const status = isCritical ? 'critical' : isWarning ? 'warning' : 'normal';
  
  const colors = {
    normal: 'text-green-600 border-green-200 bg-green-50',
    warning: 'text-yellow-600 border-yellow-200 bg-yellow-50',
    critical: 'text-red-600 border-red-200 bg-red-50'
  };

  return (
    <div className={`p-4 rounded-lg border ${colors[status]}`}>
      <h3 className="text-lg font-semibold">{title}</h3>
      <p className="text-2xl font-bold">{value.toFixed(1)}{unit}</p>
      <p className="text-sm opacity-80">Threshold: {threshold}{unit}</p>
    </div>
  );
};

const SecurityMetric = ({ title, value, type }) => {
  const colors = {
    info: 'text-blue-600 border-blue-200 bg-blue-50',
    warning: 'text-yellow-600 border-yellow-200 bg-yellow-50',
    error: 'text-red-600 border-red-200 bg-red-50'
  };

  return (
    <div className={`p-4 rounded-lg border ${colors[type]}`}>
      <h3 className="text-lg font-semibold">{title}</h3>
      <p className="text-2xl font-bold">{value.toLocaleString()}</p>
      <p className="text-sm opacity-80">Last 24 hours</p>
    </div>
  );
};

export default MetricsDashboard;