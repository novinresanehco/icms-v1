import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { TrendingUp, Users, Eye, ThumbsUp } from 'lucide-react';

const engagementData = [
  { date: '2024-03-05', views: 1250, likes: 420, shares: 65 },
  { date: '2024-03-06', views: 1400, likes: 380, shares: 72 },
  { date: '2024-03-07', views: 1800, likes: 490, shares: 85 },
  { date: '2024-03-08', views: 1600, likes: 450, shares: 78 },
  { date: '2024-03-09', views: 2000, likes: 520, shares: 95 },
];

const contentDistribution = [
  { name: 'Articles', value: 40, color: '#3B82F6' },
  { name: 'Images', value: 30, color: '#10B981' },
  { name: 'Videos', value: 20, color: '#F59E0B' },
  { name: 'Others', value: 10, color: '#6B7280' },
];

const AnalyticsDashboard = () => {
  return (
    <div className="w-full space-y-6">
      {/* Metrics Overview */}
      <div className="grid grid-cols-4 gap-4">
        {[
          { title: 'Total Views', value: '8,050', icon: Eye, trend: '+12.5%' },
          { title: 'Active Users', value: '2,240', icon: Users, trend: '+8.2%' },
          { title: 'Engagement Rate', value: '24.8%', icon: ThumbsUp, trend: '+5.4%' },
          { title: 'Growth Rate', value: '+15.2%', icon: TrendingUp, trend: '+2.1%' },
        ].map((metric, index) => (
          <Card key={index}>
            <CardContent className="p-6">
              <div className="flex justify-between items-start">
                <div>
                  <p className="text-sm text-gray-500">{metric.title}</p>
                  <h3 className="text-2xl font-bold mt-1">{metric.value}</h3>
                </div>
                <metric.icon className="w-6 h-6 text-blue-500" />
              </div>
              <div className="mt-2">
                <span className="text-sm text-green-500">{metric.trend}</span>
                <span className="text-sm text-gray-500 ml-1">vs last week</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts */}
      <div className="grid grid-cols-2 gap-4">
        <Card>
          <CardHeader>
            <CardTitle>Engagement Overview</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <LineChart width={500} height={300} data={engagementData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="date" />
                <YAxis />
                <Tooltip />
                <Line type="monotone" dataKey="views" stroke="#3B82F6" />
                <Line type="monotone" dataKey="likes" stroke="#10B981" />
                <Line type="monotone" dataKey="shares" stroke="#F59E0B" />
              </LineChart>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Content Distribution</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px] flex items-center justify-center">
              <PieChart width={300} height={300}>
                <Pie
                  data={contentDistribution}
                  cx={150}
                  cy={150}
                  innerRadius={60}
                  outerRadius={100}
                  paddingAngle={5}
                  dataKey="value"
                >
                  {contentDistribution.map((entry, index) => (
                    <Cell key={index} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default AnalyticsDashboard;
