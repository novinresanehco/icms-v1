```jsx
const DashboardMetric = ({ title, value, icon }) => {
  return (
    <div className="bg-white p-4 rounded shadow">
      <div className="flex items-center">
        <span className="text-2xl mr-3">{icon}</span>
        <div>
          <p className="text-gray-600 text-sm">{title}</p>
          <p className="text-xl font-bold">{value}</p>
        </div>
      </div>
    </div>
  );
};

const RecentOperation = ({ operation, status, time, memory }) => {
  return (
    <div className="flex justify-between items-center border-b py-2">
      <span className="text-gray-700">{operation}</span>
      <div className="flex space-x-4">
        <span className="text-gray-500">{time}</span>
        <span className="text-gray-500">{memory}</span>
        <span className={`px-2 py-1 rounded-full text-xs ${
          status === 'success' ? 'bg-green-100 text-green-800' : 
          status === 'error' ? 'bg-red-100 text-red-800' : 
          'bg-yellow-100 text-yellow-800'
        }`}>
          {status}
        </span>
      </div>
    </div>
  );
};

const DashboardChart = () => {
  const data = [
    { name: '00:00', value: 400 },
    { name: '04:00', value: 300 },
    { name: '08:00', value: 600 },
    { name: '12:00', value: 800 },
    { name: '16:00', value: 500 },
    { name: '20:00', value: 400 }
  ];

  return (
    <div className="bg-white p-4 rounded shadow">
      <h3 className="text-lg font-semibold mb-4">System Performance</h3>
      <LineChart width={600} height={200} data={data}>
        <XAxis dataKey="name" />
        <YAxis />
        <Tooltip />
        <Line type="monotone" dataKey="value" stroke="#8884d8" />
      </LineChart>
    </div>
  );
};

const MonitoringDashboard = () => {
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
        <DashboardMetric
          title="CPU Usage"
          value="45%"
          icon="💻"
        />
        <DashboardMetric
          title="Memory"
          value="2.4GB"
          icon="📊"
        />
        <DashboardMetric
          title="Disk Space"
          value="756GB"
          icon="💾"
        />
        <DashboardMetric
          title="Network"
          value="45MB/s"
          icon="🌐"
        />
      </div>

      <div className="mb-6">
        <DashboardChart />
      </div>

      <div className="bg-white p-4 rounded shadow">
        <h3 className="text-lg font-semibold mb-4">Recent Operations</h3>
        <div className="space-y-2">
          <RecentOperation
            operation="Database Backup"
            status="success"
            time="2 min ago"
            memory="45MB"
          />
          <RecentOperation
            operation="Cache Clear"
            status="success"
            time="15 min ago"
            memory="12MB"
          />
          <RecentOperation
            operation="Log Rotation"
            status="warning"
            time="1 hour ago"
            memory="8MB"
          />
        </div>
      </div>
    </div>
  );
};

export default MonitoringDashboard;
```
