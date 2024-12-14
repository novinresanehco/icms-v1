import React, { useState, useEffect } from 'react';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, AreaChart, Area } from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const DashboardMetrics = () => {
  const [metrics, setMetrics] = useState([]);
  const [timeframe, setTimeframe] = useState('1h');

  useEffect(() => {
    // Mock data for demonstration - in real implementation, this would connect to our WebSocket
    const mockData = Array.from({ length: 24 }, (_, i) => ({
      time: `${i}:00`,
      hitRate: Math.random() * 0.3 + 0.7,
      latency: Math.random() * 50 + 20,
      memoryUsage: Math.random() * 0.4 + 0.4,
      requests: Math.floor(Math.random() * 1000) + 500
    }));
    setMetrics(mockData);
  }, [timeframe]);

  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {/* Hit Rate Chart */}
      <Card className="col-span-1">
        <CardHeader>
          <CardTitle>Cache Hit Rate</CardTitle>
        </CardHeader>
        <CardContent>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={metrics}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis domain={[0, 1]} />
              <Tooltip />
              <Legend />
              <Line 
                type="monotone" 
                dataKey="hitRate" 
                stroke="#2563eb" 
                name="Hit Rate"
              />
            </LineChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Latency Chart */}
      <Card className="col-span-1">
        <CardHeader>
          <CardTitle>Response Latency</CardTitle>
        </CardHeader>
        <CardContent>
          <ResponsiveContainer width="100%" height={300}>
            <AreaChart data={metrics}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Area 
                type="monotone" 
                dataKey="latency" 
                fill="#4f46e5" 
                stroke="#4338ca" 
                name="Latency (ms)"
              />
            </AreaChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Memory Usage Chart */}
      <Card className="col-span-1">
        <CardHeader>
          <CardTitle>Memory Usage</CardTitle>
        </CardHeader>
        <CardContent>
          <ResponsiveContainer width="100%" height={300}>
            <AreaChart data={metrics}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis domain={[0, 1]} />
              <Tooltip />
              <Legend />
              <Area 
                type="monotone" 
                dataKey="memoryUsage" 
                fill="#dc2626" 
                stroke="#b91c1c" 
                name="Memory Usage"
              />
            </AreaChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Request Volume Chart */}
      <Card className="col-span-1 md:col-span-2 lg:col-span-3">
        <CardHeader>
          <CardTitle>Request Volume</CardTitle>
        </CardHeader>
        <CardContent>
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={metrics}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="time" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Bar 
                dataKey="requests" 
                fill="#0891b2" 
                name="Requests"
              />
            </BarChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>
    </div>
  );
};

const PerformanceMetrics = () => {
  const [performanceData, setPerformanceData] = useState([]);

  useEffect(() => {
    // Mock performance metrics
    const mockPerformance = Array.from({ length: 12 }, (_, i) => ({
      time: `${i * 5}min`,
      p50: Math.random() * 20 + 10,
      p90: Math.random() * 30 + 30,
      p95: Math.random() * 40 + 50,
      p99: Math.random() * 50 + 70
    }));
    setPerformanceData(mockPerformance);
  }, []);

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle>Response Time Percentiles</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={400}>
          <LineChart data={performanceData}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="time" />
            <YAxis />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="p50" stroke="#22c55e" name="P50" />
            <Line type="monotone" dataKey="p90" stroke="#eab308" name="P90" />
            <Line type="monotone" dataKey="p95" stroke="#f97316" name="P95" />
            <Line type="monotone" dataKey="p99" stroke="#dc2626" name="P99" />
          </LineChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
};

const NodeHealthMetrics = () => {
  const [nodeHealth, setNodeHealth] = useState([]);

  useEffect(() => {
    // Mock node health data
    const mockNodeHealth = Array.from({ length: 5 }, (_, i) => ({
      node: `Node ${i + 1}`,
      cpu: Math.random() * 100,
      memory: Math.random() * 100,
      network: Math.random() * 100,
      health: Math.random() * 100
    }));
    setNodeHealth(mockNodeHealth);
  }, []);

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle>Node Health Metrics</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={400}>
          <BarChart data={nodeHealth} layout="vertical">
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis type="number" domain={[0, 100]} />
            <YAxis dataKey="node" type="category" />
            <Tooltip />
            <Legend />
            <Bar dataKey="cpu" fill="#3b82f6" name="CPU Usage" />
            <Bar dataKey="memory" fill="#8b5cf6" name="Memory Usage" />
            <Bar dataKey="network" fill="#06b6d4" name="Network Load" />
            <Bar dataKey="health" fill="#22c55e" name="Health Score" />
          </BarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
};

export default function AnalyticsDashboard() {
  return (
    <div className="p-4 space-y-4">
      <DashboardMetrics />
      <div className="grid gap-4 md:grid-cols-2">
        <PerformanceMetrics />
        <NodeHealthMetrics />
      </div>
    </div>
  );
}
