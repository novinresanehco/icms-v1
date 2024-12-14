import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { Alert } from '@/components/ui/alert';

const CriticalMonitoringDashboard = () => {
  const systemMetrics = {
    security: {
      status: "PROTECTED",
      lastCheck: "30s ago",
      breachAttempts: 0,
      securityScore: 100
    },
    performance: {
      responseTime: 95,
      cpuUsage: 45,
      memoryUsage: 65,
      errorRate: 0
    },
    reliability: {
      uptime: "100%",
      failoverStatus: "READY",
      lastBackup: "5min ago",
      systemHealth: "OPTIMAL"
    }
  };

  const performanceData = [
    { time: '0800', response: 95, cpu: 45, memory: 65, errors: 0 },
    { time: '0900', response: 92, cpu: 48, memory: 68, errors: 0 },
    { time: '1000', response: 94, cpu: 46, memory: 66, errors: 0 },
    { time: '1100', response: 93, cpu: 47, memory: 67, errors: 0 }
  ];

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Alert variant="destructive" className="mb-6">
        CRITICAL SYSTEM MONITORING - ZERO ERROR TOLERANCE PROTOCOL ACTIVE
      </Alert>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Security Status</h3>
          <div className="space-y-2">
            <div className="text-green-600">System: {systemMetrics.security.status}</div>
            <div>Last Check: {systemMetrics.security.lastCheck}</div>
            <div>Breach Attempts: {systemMetrics.security.breachAttempts}</div>
            <div>Security Score: {systemMetrics.security.securityScore}%</div>
          </div>
        </div>

        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">Performance Metrics</h3>
          <div className="space-y-2">
            <div>Response: {systemMetrics.performance.responseTime}ms</div>
            <div>CPU Usage: {systemMetrics.performance.cpuUsage}%</div>
            <div>Memory: {systemMetrics.performance.memoryUsage}%</div>
            <div>Error Rate: {systemMetrics.performance.errorRate}%</div>
          </div>
        </div>

        <div className="p-4 border rounded-lg bg-white shadow">
          <h3 className="font-bold text-lg mb-2">System Health</h3>
          <div className="space-y-2">
            <div>Uptime: {systemMetrics.reliability.uptime}</div>
            <div>Failover: {systemMetrics.reliability.failoverStatus}</div>
            <div>Last Backup: {systemMetrics.reliability.lastBackup}</div>
            <div>Health: {systemMetrics.reliability.systemHealth}</div>
          </div>
        </div>
      </div>

      <div className="p-4 border rounded-lg bg-white shadow">
        <h3 className="font-bold text-lg mb-4">Real-Time Performance Monitoring</h3>
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
  );
};

export default CriticalMonitoringDashboard;