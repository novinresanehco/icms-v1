import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { Alert } from '@/components/ui/alert';

const CriticalMonitoringDashboard = () => {
  const metrics = {
    performance: [
      { time: '0800', response: 95, cpu: 45, memory: 65 },
      { time: '1000', response: 92, cpu: 48, memory: 68 },
      { time: '1200', response: 94, cpu: 46, memory: 66 },
      { time: '1400', response: 93, cpu: 47, memory: 67 }
    ],
    security: {
      status: 'SECURE',
      lastCheck: '30s ago',
      activeSessions: 142,
      failedAttempts: 0
    },
    system: {
      uptime: '99.999%',
      errorRate: '0.001%',
      activeUsers: 523,
      queueLength: 0
    }
  };

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Alert variant="destructive" className="mb-6">
        CRITICAL MONITORING ACTIVE - ZERO ERROR TOLERANCE
      </Alert>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="p-4 border rounded bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Security Status</h3>
          <div className="space-y-2">
            <div className="text-green-600">System: {metrics.security.status}</div>
            <div>Last Check: {metrics.security.lastCheck}</div>
            <div>Active Sessions: {metrics.security.activeSessions}</div>
            <div>Failed Attempts: {metrics.security.failedAttempts}</div>
          </div>
        </div>

        <div className="p-4 border rounded bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Performance</h3>
          <div className="space-y-2">
            <div>Response: {metrics.performance[0].response}ms</div>
            <div>CPU Usage: {metrics.performance[0].cpu}%</div>
            <div>Memory: {metrics.performance[0].memory}%</div>
            <div>Queue: {metrics.system.queueLength}</div>
          </div>
        </div>

        <div className="p-4 border rounded bg-white shadow">
          <h3 className="font-bold text-lg mb-2">System Health</h3>
          <div className="space-y-2">
            <div>Uptime: {metrics.system.uptime}</div>
            <div>Error Rate: {metrics.system.errorRate}</div>
            <div>Active Users: {metrics.system.activeUsers}</div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6">
        <div className="p-4 border rounded bg-white shadow">
          <h3 className="font-bold text-lg mb-4">System Performance</h3>
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={metrics.performance}>
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
                <Line 
                  type="monotone" 
                  dataKey="memory" 
                  stroke="#dc2626" 
                  name="Memory Usage (%)" 
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>
    </div>
  );
};

export default CriticalMonitoringDashboard;