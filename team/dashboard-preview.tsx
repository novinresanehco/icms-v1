import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';

const data = [
  { name: 'Jan', users: 2854, retention: 88 },
  { name: 'Feb', users: 2960, retention: 89 },
  { name: 'Mar', users: 3112, retention: 85 },
  { name: 'Apr', users: 3080, retention: 87 },
  { name: 'May', users: 3350, retention: 90 }
];

const DashboardPreview = () => {
  return (
    <div className="w-full space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>User Analytics Dashboard</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-[300px] w-full pt-4">
            <LineChart data={data} width={600} height={250}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="name" />
              <YAxis yAxisId="left" />
              <YAxis yAxisId="right" orientation="right" />
              <Tooltip />
              <Line yAxisId="left" type="monotone" dataKey="users" stroke="#8884d8" />
              <Line yAxisId="right" type="monotone" dataKey="retention" stroke="#82ca9d" />
            </LineChart>
          </div>
          <div className="grid grid-cols-2 gap-4 mt-4">
            <Card>
              <CardContent className="p-4">
                <h3 className="font-bold text-lg">Total Users</h3>
                <p className="text-2xl">15,356</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4">
                <h3 className="font-bold text-lg">Avg. Retention</h3>
                <p className="text-2xl">87.8%</p>
              </CardContent>
            </Card>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default DashboardPreview;
