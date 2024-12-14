```jsx
import React from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend } from 'recharts';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';

const MonitoringDashboard = () => (
  <div className="p-6 space-y-6">
    <div className="flex justify-between items-center">
      <h1 className="text-2xl font-bold">System Monitoring</h1>
      <select 
        className="p-2 border rounded"
        defaultValue="1h"
      >
        <option value="1h">Last Hour</option>
        <option value="6h">Last 6 Hours</option>
        <option value="24h">Last 24 Hours</option>
      </select>
    </div>

    <div className="grid grid-cols-4 gap-4">
      <div className="p-4 bg-white rounded-lg shadow">
        <div className="flex items-center">
          <div className="p-2 rounded-lg bg-blue-100 text-2xl">⏱️</div>
          <div className="ml-3">
            <h3 className="text-sm font-medium text-gray-500">Response Time</h3>
            <p className="text-lg font-semibold">150ms</p>
          </div>
        </div>
      </div>

      <div className="p-4 bg-white rounded-lg shadow">
        <div className="flex items-center">
          <div className="p-2 rounded-lg bg-blue-100 text-2xl">🔄</div>
          <div className="ml-3">
            <h3 className="text-sm font-medium text-gray-500">Queries</h3>
            <p className="text-lg font-semibold">1,234</p>
          </div>
        </div>
      </div>

      <div className="p-4 bg-white rounded-lg shadow">
        <div className="flex items-center">
          <div className="p-2 rounded-lg bg-blue-100 text-2xl">💾</div>
          <div className="ml-3">
            <h3 className="text-sm font-medium text-gray-500">Memory</h3>
            <p className="text-lg font-semibold">64MB</p>
          </div>
        </div>
      </div>

      <div className="p-4 bg-white rounded-lg shadow">
        <div className="flex items-center">
          <div className="p-2 rounded-lg bg-blue-100 text-2xl">🔔</div>
          <div className="ml-3">
            <h3 className="text-sm font-medium text-gray-500">Alerts</h3>
            <p className="text-lg font-semibold">0</p>
          </div>
        </div>
      </div>
    </div>

    <div className="grid grid-cols-2 gap-4">
      <div className="p-4 bg-white rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-4">Response Time Trend</h3>
        <LineChart width={500} height={300} data={[]}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis />
          <YAxis />
          <Tooltip />
          <Legend />
          <Line type="monotone" dataKey="value" stroke="#8884d8" name="Response Time (ms)" />
        </LineChart>
      </div>

      <div className="p-4 bg-white rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-4">Memory Usage Trend</h3>
        <LineChart width={500} height={300} data={[]}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis />
          <YAxis />
          <Tooltip />
          <Legend />
          <Line type="monotone" dataKey="value" stroke="#82ca9d" name="Memory (MB)" />
        </LineChart>
      </div>
    </div>

    <div className="space-y-4">
      <h3 className="text-lg font-semibold">Active Alerts</h3>
      <div className="text-green-600">No active alerts</div>
    </div>

    <div className="mt-6">
      <h3 className="text-lg font-semibold mb-4">Recent Operations</h3>
      <table className="min-w-full">
        <thead>
          <tr className="bg-gray-50">
            <th className="p-2 text-left">Operation</th>
            <th className="p-2 text-left">Duration</th>
            <th className="p-2 text-left">Queries</th>
            <th className="p-2 text-left">Memory</th>
            <th className="p-2 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-t">
            <td className="p-2">Page Load</td>
            <td className="p-2">150ms</td>
            <td className="p-2">5</td>
            <td className="p-2">2.5MB</td>
            <td className="p-2">
              <span className="px-2 py-1 rounded text-sm bg-green-100 text-green-800">
                success
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
);

export default MonitoringDashboard;
```
