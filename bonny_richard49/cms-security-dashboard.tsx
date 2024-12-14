import React, { useState, useEffect } from 'react';
import { AlertTriangle, Shield, Activity, Lock } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';

const SecurityDashboard = () => {
  const [securityMetrics, setSecurityMetrics] = useState({
    activeThreats: 0,
    securityScore: 100,
    activeUsers: 0,
    lastAudit: new Date()
  });

  const [systemStatus, setSystemStatus] = useState({
    cpuUsage: 0,
    memoryUsage: 0,
    responseTime: 0,
    uptime: 100
  });

  useEffect(() => {
    // Simulated monitoring updates
    const interval = setInterval(() => {
      setSecurityMetrics(prev => ({
        ...prev,
        activeUsers: Math.floor(Math.random() * 100),
        securityScore: Math.max(95, Math.floor(Math.random() * 100))
      }));

      setSystemStatus(prev => ({
        cpuUsage: Math.floor(Math.random() * 60),
        memoryUsage: Math.floor(Math.random() * 70),
        responseTime: Math.floor(Math.random() * 100),
        uptime: 99.999
      }));
    }, 3000);

    return () => clearInterval(interval);
  }, []);

  return (
    <div className="w-full max-w-6xl mx-auto p-6 space-y-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Security Dashboard</h1>
        <div className="flex items-center space-x-2">
          <Lock className="h-5 w-5 text-green-500" />
          <span className="text-green-500 font-medium">System Secured</span>
        </div>
      </div>

      {/* Security Alerts */}
      <Alert className="bg-yellow-50 border-yellow-200">
        <AlertTriangle className="h-4 w-4 text-yellow-600" />
        <AlertTitle>Security Status</AlertTitle>
        <AlertDescription>
          System is under active monitoring. Security score: {securityMetrics.securityScore}%
        </AlertDescription>
      </Alert>

      {/* Metrics Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Active Users */}
        <div className="bg-white p-4 rounded-lg shadow-sm border">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-600">Active Users</h3>
            <Activity className="h-4 w-4 text-blue-500" />
          </div>
          <p className="mt-2 text-2xl font-semibold">{securityMetrics.activeUsers}</p>
        </div>

        {/* CPU Usage */}
        <div className="bg-white p-4 rounded-lg shadow-sm border">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-600">CPU Usage</h3>
            <Activity className="h-4 w-4 text-green-500" />
          </div>
          <p className="mt-2 text-2xl font-semibold">{systemStatus.cpuUsage}%</p>
        </div>

        {/* Memory Usage */}
        <div className="bg-white p-4 rounded-lg shadow-sm border">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-600">Memory Usage</h3>
            <Activity className="h-4 w-4 text-yellow-500" />
          </div>
          <p className="mt-2 text-2xl font-semibold">{systemStatus.memoryUsage}%</p>
        </div>

        {/* Response Time */}
        <div className="bg-white p-4 rounded-lg shadow-sm border">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-600">Response Time</h3>
            <Activity className="h-4 w-4 text-purple-500" />
          </div>
          <p className="mt-2 text-2xl font-semibold">{systemStatus.responseTime}ms</p>
        </div>
      </div>

      {/* System Status */}
      <div className="mt-6 bg-white p-6 rounded-lg shadow-sm border">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">System Status</h2>
          <Shield className="h-5 w-5 text-green-500" />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div className="p-4 bg-gray-50 rounded-lg">
            <h3 className="text-sm font-medium text-gray-600">System Uptime</h3>
            <p className="mt-2 text-xl font-semibold">{systemStatus.uptime}%</p>
          </div>
          <div className="p-4 bg-gray-50 rounded-lg">
            <h3 className="text-sm font-medium text-gray-600">Security Score</h3>
            <p className="mt-2 text-xl font-semibold">{securityMetrics.securityScore}%</p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default SecurityDashboard;