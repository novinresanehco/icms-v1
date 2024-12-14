import React from 'react';

const MonitoringDashboard = () => {
  return (
    <div className="max-w-4xl p-6 bg-gray-50 rounded-lg">
      <div className="grid grid-cols-3 gap-4 mb-6">
        <div className="p-4 bg-red-50 border border-red-200 rounded">
          <h3 className="font-bold mb-2">PHASE 1: 24H</h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span>Security Core</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>CMS Foundation</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>Infrastructure</span>
              <span className="font-mono">0%</span>
            </div>
          </div>
        </div>

        <div className="p-4 bg-yellow-50 border border-yellow-200 rounded">
          <h3 className="font-bold mb-2">PHASE 2: 24H</h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span>Content System</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>Security Integration</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>Performance</span>
              <span className="font-mono">0%</span>
            </div>
          </div>
        </div>

        <div className="p-4 bg-green-50 border border-green-200 rounded">
          <h3 className="font-bold mb-2">PHASE 3: 24H</h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span>Testing</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>Documentation</span>
              <span className="font-mono">0%</span>
            </div>
            <div className="flex justify-between">
              <span>Deployment</span>
              <span className="font-mono">0%</span>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="p-4 bg-blue-50 border border-blue-200 rounded">
          <h3 className="font-bold mb-2">CRITICAL METRICS</h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span>API Response</span>
              <span className="font-mono text-red-600">&lt;100ms</span>
            </div>
            <div className="flex justify-between">
              <span>Page Load</span>
              <span className="font-mono text-red-600">&lt;200ms</span>
            </div>
            <div className="flex justify-between">
              <span>Test Coverage</span>
              <span className="font-mono text-red-600">100%</span>
            </div>
          </div>
        </div>

        <div className="p-4 bg-purple-50 border border-purple-200 rounded">
          <h3 className="font-bold mb-2">SECURITY STATUS</h3>
          <div className="space-y-2 text-sm">
            <div className="flex justify-between">
              <span>Authentication</span>
              <span className="font-mono">Pending</span>
            </div>
            <div className="flex justify-between">
              <span>Encryption</span>
              <span className="font-mono">Pending</span>
            </div>
            <div className="flex justify-between">
              <span>Audit System</span>
              <span className="font-mono">Pending</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default MonitoringDashboard;
