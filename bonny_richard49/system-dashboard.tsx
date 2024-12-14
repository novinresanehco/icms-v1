import React, { useState, useEffect } from 'react';
import { 
  LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend,
  ResponsiveContainer, BarChart, Bar 
} from 'recharts';
import { Alert, AlertTitle } from '@/components/ui/alert';

const SystemDashboard = () => {
  const [metrics, setMetrics] = useState({
    performance: {
      response_times: [], // Last 24 hours
      error_rates: [],
      resource_usage: []
    },
    security: {
      failed_attempts: 0,
      active_sessions: 0,
      security_alerts: []
    },
    system: {
      uptime: '99.999%',
      last_backup: '2 mins ago',
      active_users: 142
    }
  });

  const performanceData = [
    { time: '00:00', response: 95, errors: 0, cpu: 45 },
    { time: '04:00', response: 87, errors: 0, cpu: 48 },
    { time: '08:00', response: 91, errors: 1, cpu: 52 },
    { time: '12:00', response: 93, errors: 0, cpu: 49 },
    { time: '16:00', response: 89, errors: 0, cpu: 51 },
    { time: '20:00', response: 88, errors: 0, cpu: 47 }
  ];

  return (
    <div className="w-full max-w-7xl mx-auto p-4 space-y-6">
      {/* Critical Alerts */}
      <div className="mb-6">
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>Critical Security Notice</AlertTitle>
          Heightened security monitoring active. Zero-tolerance protocol engaged.
        </Alert>
      </div>

      {/* Key Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="text-lg font-semibold mb-2">System Status</h3>
          <div className="space-y-2">
            <p>Uptime: {metrics.system.uptime}</p>
            <p>Last Backup: {metrics.system.last_backup}</p>
            <p>Active Users: {metrics.system.active_users}</p>
          </div>
        </div>
        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="text-lg font-semibold mb-2">Security</h3>
          <div className="space-y-2">
            <p>Failed Attempts: {metrics.security.failed_attempts}</p>
            <p>Active Sessions: {metrics.security.active_sessions}</p>
            <p className="text-green-600">All Systems Secured</p>
          </div>
        </div>
        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="text-lg font-semibold mb-2">Performance</h3>
          <div className="space-y-2">
            <p>Avg Response: 92ms</p>
            <p>Error Rate: 0.001%</p>
            <p>CPU Usage: 48%</p>
          </div>
        </div>
      </div>

      {/* Performance Chart */}
      <div className="p-4 border rounded-lg bg-white shadow">
        <h3 className="text-lg font-semibold mb-4">System Performance (24h)</h3>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={performanceData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Line 
                type="monotone" 
                dataKey="response" 
                stroke="#2563eb" 
                name="Response Time (ms)"
              />
              <Line 
                type="monotone" 
                dataKey="cpu" 
                stroke="#16a34a" 
                name="CPU Usage (%)"
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Security Events */}
      <div className="p-4 border rounded-lg bg-white shadow">
        <h3 className="text-lg font-semibold mb-4">Security Events</h3>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={performanceData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Bar dataKey="errors" fill="#dc2626" name="Security Events" />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
};

export default SystemDashboard;