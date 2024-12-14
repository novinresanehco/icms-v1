```jsx
import React from 'react';
import { LineChart, Line, XAxis, YAxis, Tooltip } from 'recharts';

const chartData = [
  { name: '00:00', value: 400 },
  { name: '04:00', value: 300 },
  { name: '08:00', value: 600 },
  { name: '12:00', value: 800 },
  { name: '16:00', value: 500 },
  { name: '20:00', value: 400 }
];

function MonitoringDashboard() {
  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">System Dashboard</h1>
        <select className="border p-2 rounded">
          <option>Last 24 hours</option>
          <option>Last 7 days</option>
          <option>Last 30 days</option>
        </select>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white p-4 rounded shadow">
          <div className="flex items-center">
            <span className="text-2xl mr-3">💻</span>
            <div>
              <p className="text-gray-600 text-sm">CPU Usage</p>
              <p className="text-xl font-bold">45%</p>
            </div>
          </div>
        </div>

        <div className="bg-white p-4 rounded shadow">
          <div className="flex items-center">
            <span className="text-2xl mr-3">📊</span>
            <div>
              <p className="text-gray-600 text-sm">Memory</p>
              <p className="text-xl font-bold">2.4GB</p>
            </div>
          </div>
        </div>

        <div className="bg-white p-4 rounded shadow">
          <div className="flex items-center">
            <span className="text-2xl mr-3">💾</span>
            <div>
              <p className="text-gray-600 text-sm">Disk Space</p>
              <p className="text-xl font-bold">756GB</p>
            </div>
          </div>
        </div>

        <div className="bg-white p-4 rounded shadow">
          <div className="flex items-center">
            <span className="text-2xl mr-3">🌐</span>
            <div>
              <p className="text-gray-600 text-sm">Network</p>
              <p className="text-xl font-bold">45MB/s</p>
            </div>
          </div>
        </div>
      </div>

      <div className="bg-white p-4 rounded shadow mb-6">
        <h3 className="text-lg font-semibold mb-4">System Performance</h3>
        <LineChart width={600} height={200} data={chartData}>
          <XAxis dataKey="name" />
          <YAxis />
          <Tooltip />
          <Line type="monotone" dataKey="value" stroke="#8884d8" />
        </LineChart>
      </div>

      <div className="bg-white p-4 rounded shadow">
        <h3 className="text-lg font-semibold mb-4">Recent Operations</h3>
        <div className="space-y-2">
          <div className="flex justify-between items-center border-b py-2">
            <span className="text-gray-700">Database Backup</span>
            <div className="flex space-x-4">
              <span className="text-gray-500">2 min ago</span>
              <span className="text-gray-500">45MB</span>
              <span className="px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                success
              </span>
            </div>
          </div>

          <div className="flex justify-between items-center border-b py-2">
            <span className="text-gray-700">Cache Clear</span>
            <div className="flex space-x-4">
              <span className="text-gray-500">15 min ago</span>
              <span className="text-gray-500">12MB</span>
              <span className="px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                success
              </span>
            </div>
          </div>

          <div className="flex justify-between items-center border-b py-2">
            <span className="text-gray-700">Log Rotation</span>
            <div className="flex space-x-4">
              <span className="text-gray-500">1 hour ago</span>
              <span className="text-gray-500">8MB</span>
              <span className="px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                warning
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export default MonitoringDashboard;
```
