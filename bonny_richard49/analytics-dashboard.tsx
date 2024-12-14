import React, { useState, useEffect } from 'react';
import { LineChart, Line, BarChart, Bar, PieChart, Pie, CartesianGrid, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Calendar } from '@/components/ui/calendar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';

const AnalyticsDashboard = () => {
  const [metrics, setMetrics] = useState({
    deliveryMetrics: [],
    performanceMetrics: [],
    engagementMetrics: []
  });
  const [timeframe, setTimeframe] = useState('7d');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchAnalyticsData();
  }, [timeframe]);

  const fetchAnalyticsData = async () => {
    try {
      setLoading(true);
      const response = await window.fs.readFile('notification_analytics.json', { encoding: 'utf8' });
      const data = JSON.parse(response);
      setMetrics(data);
      setError(null);
    } catch (err) {
      setError('Failed to load analytics data');
      console.error('Error loading analytics:', err);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-96">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-gray-900"></div>
      </div>
    );
  }

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold">Notification Analytics Dashboard</h1>
        <div className="flex space-x-4">
          <Select value={timeframe} onValueChange={setTimeframe}>
            <SelectTrigger className="w-32">
              <SelectValue placeholder="Select timeframe" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="24h">Last 24 Hours</SelectItem>
              <SelectItem value="7d">Last 7 Days</SelectItem>
              <SelectItem value="30d">Last 30 Days</SelectItem>
              <SelectItem value="90d">Last 90 Days</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <MetricCard
          title="Delivery Success Rate"
          value={`${metrics.deliveryMetrics.successRate}%`}
          trend={metrics.deliveryMetrics.trend}
        />
        <MetricCard
          title="Average Delivery Time"
          value={`${metrics.deliveryMetrics.avgDeliveryTime}s`}
          trend={metrics.deliveryMetrics.trend}
        />
        <MetricCard
          title="Engagement Rate"
          value={`${metrics.engagementMetrics.engagementRate}%`}
          trend={metrics.engagementMetrics.trend}
        />
      </div>

      <Tabs defaultValue="delivery">
        <TabsList>
          <TabsTrigger value="delivery">Delivery Metrics</TabsTrigger>
          <TabsTrigger value="performance">Performance</TabsTrigger>
          <TabsTrigger value="engagement">Engagement</TabsTrigger>
        </TabsList>

        <TabsContent value="delivery" className="mt-6">
          <DeliveryMetricsChart data={metrics.deliveryMetrics.timeline} />
        </TabsContent>

        <TabsContent value="performance" className="mt-6">
          <PerformanceMetricsChart data={metrics.performanceMetrics} />
        </TabsContent>

        <TabsContent value="engagement" className="mt-6">
          <EngagementMetricsChart data={metrics.engagementMetrics.timeline} />
        </TabsContent>
      </Tabs>
    </div>
  );
};

const MetricCard = ({ title, value, trend }) => (
  <Card>
    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
      <CardTitle className="text-sm font-medium">{title}</CardTitle>
      <Badge variant={trend > 0 ? "success" : "destructive"}>
        {trend > 0 ? '↑' : '↓'} {Math.abs(trend)}%
      </Badge>
    </CardHeader>
    <CardContent>
      <div className="text-2xl font-bold">{value}</div>
    </CardContent>
  </Card>
);

const DeliveryMetricsChart = ({ data }) => (
  <Card>
    <CardHeader>
      <CardTitle>Delivery Metrics Over Time</CardTitle>
    </CardHeader>
    <CardContent>
      <div className="h-96">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="timestamp" />
            <YAxis />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="successRate" stroke="#4f46e5" name="Success Rate" />
            <Line type="monotone" dataKey="deliveryTime" stroke="#059669" name="Delivery Time" />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </CardContent>
  </Card>
);

const PerformanceMetricsChart = ({ data }) => (
  <Card>
    <CardHeader>
      <CardTitle>Performance Distribution</CardTitle>
    </CardHeader>
    <CardContent>
      <div className="h-96">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie
              data={data.distribution}
              dataKey="value"
              nameKey="name"
              cx="50%"
              cy="50%"
              outerRadius={150}
              fill="#4f46e5"
              label
            />
            <Tooltip />
            <Legend />
          </PieChart>
        </ResponsiveContainer>
      </div>
    </CardContent>
  </Card>
);

const EngagementMetricsChart = ({ data }) => (
  <Card>
    <CardHeader>
      <CardTitle>Engagement Funnel</CardTitle>
    </CardHeader>
    <CardContent>
      <div className="h-96">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data}>
            <CartesianGrid strokeDasharray="3 3" />
            <XAxis dataKey="stage" />
            <YAxis />
            <Tooltip />
            <Legend />
            <Bar dataKey="count" fill="#4f46e5" name="Users" />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </CardContent>
  </Card>
);

export default AnalyticsDashboard;