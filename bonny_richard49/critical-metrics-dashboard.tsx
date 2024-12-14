import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { Alert } from '@/components/ui/alert';

const CriticalMetricsDashboard = () => {
  const performanceData = [
    { time: '00:00', response: 95, errors: 0, cpu: 45 },
    { time: '04:00', response: 87, errors: 0, cpu: 48 },
    { time: '08:00', response: 91, errors: 0, cpu: 52 },
    { time: '12:00', response: 93, errors: 0, cpu: 49 }
  ];

  return (
    <div className="w-full max-w-7xl mx-auto">
      <Alert variant="destructive" className="mb-4">
        CRITICAL SYSTEM MONITORING ACTIVE - ZERO ERROR TOLERANCE
      </Alert>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="p-4 border rounded bg-white">
          <h3 className="font-bold">Security Status</h3>
          <div className="mt-2">
            <div className="text-green-600">All Systems Secure</div>
            <div>Last Check: 30s ago</div>
            <div>Active Sessions: 142</div>
          </div>
        </div>

        <div className="p-4 border rounded bg-white">
          <h3 className="font-bold">Performance</h3>
          <div className="mt-2">
            <div>Response Time: 92ms</div>
            <div>Error Rate: 0.001%</div>
            <div>CPU Usage: 48%</div>
          </div>
        </div>

        <div className="p-4 border rounded bg-white">
          <h3 className="font-bold">System Health</h3>
          <div className="mt-2">
            <div>Uptime: 99.999%</div>
            <div>Memory: 62%</div>
            <div>Disk: 45%</div>
          </div>
        </div>
      </div>

      <div className="p-4 border rounded bg-white">
        <h3 className="font-bold mb-4">System Performance (24h)</h3>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={performanceData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
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
    </div>
  );
};

export default CriticalMetricsDashboard;