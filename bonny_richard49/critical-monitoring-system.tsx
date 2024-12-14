import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { Alert } from '@/components/ui/alert';

const CriticalMonitor = () => {
  const performanceMetrics = [
    { time: '0800', response: 95, cpu: 45, memory: 65, errors: 0 },
    { time: '0900', response: 92, cpu: 48, memory: 68, errors: 0 },
    { time: '1000', response: 94, cpu: 46, memory: 66, errors: 0 },
    { time: '1100', response: 93, cpu: 47, memory: 67, errors: 0 }
  ];

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Alert variant="destructive" className="mb-6">
        CRITICAL SYSTEM MONITORING - ZERO ERROR TOLERANCE
      </Alert>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Security Status</h3>
          <div className="space-y-2">
            <div className="text-green-600">System: SECURE</div>
            <div>Authentication: VERIFIED</div>
            <div>Access Control: ENFORCED</div>
            <div>Encryption: ACTIVE</div>
          </div>
        </div>

        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Performance</h3>
          <div className="space-y-2">
            <div>Response: 93ms</div>
            <div>CPU Usage: 47%</div>
            <div>Memory: 67%</div>
            <div>Error Rate: 0%</div>
          </div>
        </div>

        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">System Health</h3>
          <div className="space-y-2">
            <div>Uptime: 100%</div>
            <div>Services: ALL ACTIVE</div>
            <div>Database: OPTIMAL</div>
            <div>Cache: SYNCHRONIZED</div>
          </div>
        </div>
      </div>

      <div className="p-4 border rounded-lg bg-white shadow">
        <h3 className="font-bold text-lg mb-4">Performance Monitoring</h3>
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={performanceMetrics}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
              <Line type="monotone" dataKey="response" stroke="#2563eb" name="Response (ms)" />
              <Line type="monotone" dataKey="cpu" stroke="#16a34a" name="CPU (%)" />
              <Line type="monotone" dataKey="memory" stroke="#dc2626" name="Memory (%)" />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  );
};

export default CriticalMonitor;