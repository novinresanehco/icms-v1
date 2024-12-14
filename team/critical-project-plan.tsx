import React from 'react';

const CriticalPlan = () => {
  return (
    <div className="max-w-4xl p-6 space-y-8">
      <div className="border-b border-red-600 pb-4">
        <h2 className="text-xl font-bold text-red-600 mb-2">CRITICAL PRIORITIES - 72HR DEADLINE</h2>
        
        <div className="grid grid-cols-3 gap-4">
          <div className="p-4 border border-red-200 rounded">
            <div className="font-bold mb-2">DAY 1 (24HR)</div>
            <ul className="list-disc pl-4 text-sm space-y-1">
              <li>Core Security Framework</li>
              <li>Authentication System</li>
              <li>Base CMS Architecture</li>
              <li>Critical Data Layer</li>
            </ul>
          </div>

          <div className="p-4 border border-red-200 rounded">
            <div className="font-bold mb-2">DAY 2 (24HR)</div>
            <ul className="list-disc pl-4 text-sm space-y-1">
              <li>Content Management</li>
              <li>Access Control</li>
              <li>Cache System</li>
              <li>API Endpoints</li>
            </ul>
          </div>

          <div className="p-4 border border-red-200 rounded">
            <div className="font-bold mb-2">DAY 3 (24HR)</div>
            <ul className="list-disc pl-4 text-sm space-y-1">
              <li>Testing & Validation</li>
              <li>Performance Optimization</li>
              <li>Documentation</li>
              <li>Deployment Process</li>
            </ul>
          </div>
        </div>
      </div>

      <div className="space-y-6">
        <div className="border-l-4 border-red-600 pl-4">
          <h3 className="font-bold mb-2">SUCCESS METRICS</h3>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <div className="font-semibold">Performance</div>
              <ul className="list-disc pl-4">
                <li>API Response: &lt;100ms</li>
                <li>Page Load: &lt;200ms</li>
                <li>Database: &lt;50ms</li>
              </ul>
            </div>
            <div>
              <div className="font-semibold">Security</div>
              <ul className="list-disc pl-4">
                <li>Multi-factor Auth</li>
                <li>Encryption: AES-256</li>
                <li>Full Audit Trail</li>
              </ul>
            </div>
          </div>
        </div>

        <div className="border-l-4 border-red-600 pl-4">
          <h3 className="font-bold mb-2">TEAM ASSIGNMENTS</h3>
          <div className="grid grid-cols-3 gap-4 text-sm">
            <div>
              <div className="font-semibold">Security Lead</div>
              <ul className="list-disc pl-4">
                <li>Authentication</li>
                <li>Authorization</li>
                <li>Encryption</li>
              </ul>
            </div>
            <div>
              <div className="font-semibold">CMS Lead</div>
              <ul className="list-disc pl-4">
                <li>Content System</li>
                <li>Media Handling</li>
                <li>Templates</li>
              </ul>
            </div>
            <div>
              <div className="font-semibold">Infrastructure</div>
              <ul className="list-disc pl-4">
                <li>Caching</li>
                <li>Database</li>
                <li>Monitoring</li>
              </ul>
            </div>
          </div>
        </div>

        <div className="border-l-4 border-red-600 pl-4">
          <h3 className="font-bold mb-2">VALIDATION CHECKPOINTS</h3>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div>
              <div className="font-semibold">Code Quality</div>
              <ul className="list-disc pl-4">
                <li>Test Coverage: 100%</li>
                <li>Static Analysis</li>
                <li>Peer Review</li>
              </ul>
            </div>
            <div>
              <div className="font-semibold">Security</div>
              <ul className="list-disc pl-4">
                <li>Vulnerability Scan</li>
                <li>Penetration Test</li>
                <li>Compliance Check</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default CriticalPlan;
