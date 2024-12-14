import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { FileText, Image, Video, AlertCircle } from 'lucide-react';

const statusData = [
  { name: 'Published', value: 45, color: '#10B981' },
  { name: 'Draft', value: 30, color: '#6B7280' },
  { name: 'Pending', value: 15, color: '#F59E0B' },
  { name: 'Rejected', value: 10, color: '#EF4444' }
];

const typeData = [
  { name: 'Articles', count: 48, icon: FileText },
  { name: 'Images', count: 156, icon: Image },
  { name: 'Videos', count: 23, icon: Video },
  { name: 'Reports', count: 19, icon: AlertCircle }
];

const activityData = [
  { day: 'Mon', posts: 15, edits: 25 },
  { day: 'Tue', posts: 20, edits: 30 },
  { day: 'Wed', posts: 25, edits: 22 },
  { day: 'Thu', posts: 18, edits: 28 },
  { day: 'Fri', posts: 22, edits: 32 }
];

const ContentDashboard = () => {
  return (
    <div className="w-full space-y-6">
      <div className="grid grid-cols-4 gap-4">
        {typeData.map((item) => (
          <Card key={item.name}>
            <CardContent className="p-6 flex items-center space-x-4">
              <item.icon className="h-8 w-8 text-blue-500" />
              <div>
                <p className="text-sm text-gray-500">{item.name}</p>
                <p className="text-2xl font-bold">{item.count}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Card>
          <CardHeader>
            <CardTitle>Content Status Distribution</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px] flex justify-center">
              <PieChart width={300} height={300}>
                <Pie
                  data={statusData}
                  cx={150}
                  cy={150}
                  innerRadius={60}
                  outerRadius={100}
                  paddingAngle={5}
                  dataKey="value"
                >
                  {statusData.map((entry, index) => (
                    <Cell key={index} fill={entry.color} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Weekly Activity</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <BarChart width={400} height={300} data={activityData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="day" />
                <YAxis />
                <Tooltip />
                <Bar dataKey="posts" fill="#8884d8" name="New Posts" />
                <Bar dataKey="edits" fill="#82ca9d" name="Edits" />
              </BarChart>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default ContentDashboard;
