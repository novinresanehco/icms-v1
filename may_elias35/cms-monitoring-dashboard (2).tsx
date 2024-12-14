```jsx
function MonitoringDashboard() {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-6">System Monitor</h1>
      
      <div className="grid grid-cols-4 gap-4 mb-6">
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500">CPU Usage</p>
          <p className="text-2xl font-bold">45%</p>
        </div>
        
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500">Memory</p>
          <p className="text-2xl font-bold">2.4 GB</p>
        </div>
        
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500">Disk Space</p>
          <p className="text-2xl font-bold">756 GB</p>
        </div>
        
        <div className="bg-white p-4 rounded-lg shadow">
          <p className="text-gray-500">Network</p>
          <p className="text-2xl font-bold">45 MB/s</p>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow">
        <h2 className="text-lg font-bold mb-4">Recent Operations</h2>
        <div className="space-y-2">
          <div className="flex justify-between p-2 border-b">
            <span>Database Backup</span>
            <span className="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">Success</span>
          </div>
          <div className="flex justify-between p-2 border-b">
            <span>Cache Clear</span>
            <span className="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">Success</span>
          </div>
          <div className="flex justify-between p-2 border-b">
            <span>Log Rotation</span>
            <span className="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-sm">Warning</span>
          </div>
        </div>
      </div>
    </div>
  );
}

export default MonitoringDashboard;
```
