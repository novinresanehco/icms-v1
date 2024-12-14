import React, { useState, useEffect } from 'react';
import { Bell, AlertCircle, CheckCircle, XCircle, Clock } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const AlertDashboard = () => {
  const [alerts, setAlerts] = useState([]);
  const [activeFilter, setActiveFilter] = useState('all');
  const [isExpanded, setIsExpanded] = useState({});

  useEffect(() => {
    // Simulated alerts data - would be real-time in production
    const mockAlerts = [
      {
        id: 1,
        level: 'critical',
        message: 'Cache hit rate below 60%',
        timestamp: new Date().toISOString(),
        metric: 'hit_rate',
        value: 0.58,
        threshold: 0.6,
        status: 'active'
      },
      {
        id: 2,
        level: 'warning',
        message: 'Memory usage above 80%',
        timestamp: new Date().toISOString(),
        metric: 'memory_usage',
        value: 0.82,
        threshold: 0.8,
        status: 'active'
      }
    ];
    setAlerts(mockAlerts);
  }, []);

  const getSeverityColor = (level) => {
    switch (level) {
      case 'critical':
        return 'text-red-600 bg-red-50';
      case 'warning':
        return 'text-yellow-600 bg-yellow-50';
      case 'info':
        return 'text-blue-600 bg-blue-50';
      default:
        return 'text-gray-600 bg-gray-50';
    }
  };

  return (
    <div className="space-y-4">
      {/* Alert Summary */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Bell className="w-5 h-5" />
            Alert Summary
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-4 gap-4">
            <MetricCard
              title="Active Alerts"
              value={alerts.filter(a => a.status === 'active').length}
              icon={<AlertCircle className="w-5 h-5 text-red-500" />}
            />
            <MetricCard
              title="Warning Level"
              value={alerts.filter(a => a.level === 'warning').length}
              icon={<Clock className="w-5 h-5 text-yellow-500" />}
            />
            <MetricCard
              title="Critical Level"
              value={alerts.filter(a => a.level === 'critical').length}
              icon={<XCircle className="w-5 h-5 text-red-500" />}
            />
            <MetricCard
              title="Resolved Today"
              value={alerts.filter(a => a.status === 'resolved').length}
              icon={<CheckCircle className="w-5 h-5 text-green-500" />}
            />
          </div>
        </CardContent>
      </Card>

      {/* Alert List */}
      <Card>
        <CardHeader>
          <CardTitle>Active Alerts</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            {alerts.map(alert => (
              <div
                key={alert.id}
                className={`p-4 rounded-lg ${getSeverityColor(alert.level)} cursor-pointer`}
                onClick={() => setIsExpanded({ ...isExpanded, [alert.id]: !isExpanded[alert.id] })}
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <AlertIcon level={alert.level} />
                    <span className="font-medium">{alert.message}</span>
                  </div>
                  <span className="text-sm">
                    {new Date(alert.timestamp).toLocaleTimeString()}
                  </span>
                </div>
                
                {isExpanded[alert.id] && (
                  <div className="mt-4 space-y-2 text-sm">
                    <div>Metric: {alert.metric}</div>
                    <div>Current Value: {alert.value}</div>
                    <div>Threshold: {alert.threshold}</div>
                    <div className="flex gap-2 mt-4">
                      <button className="px-3 py-1 text-white bg-blue-500 rounded hover:bg-blue-600">
                        Acknowledge
                      </button>
                      <button className="px-3 py-1 text-white bg-green-500 rounded hover:bg-green-600">
                        Resolve
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Alert Timeline */}
      <Card>
        <CardHeader>
          <CardTitle>Alert Timeline</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="relative">
            <div className="absolute left-4 h-full w-0.5 bg-gray-200"></div>
            {alerts.map(alert => (
              <div key={alert.id} className="relative flex items-start gap-4 mb-4 ml-4">
                <div className={`w-2 h-2 rounded-full mt-2 ${
                  alert.level === 'critical' ? 'bg-red-500' : 'bg-yellow-500'
                }`}></div>
                <div>
                  <div className="font-medium">{alert.message}</div>
                  <div className="text-sm text-gray-500">
                    {new Date(alert.timestamp).toLocaleString()}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

const MetricCard = ({ title, value, icon }) => {
  return (
    <div className="p-4 bg-white rounded-lg shadow">
      <div className="flex items-center justify-between">
        <div>
          <div className="text-sm text-gray-500">{title}</div>
          <div className="text-2xl font-semibold">{value}</div>
        </div>
        {icon}
      </div>
    </div>
  );
};

const AlertIcon = ({ level }) => {
  switch (level) {
    case 'critical':
      return <XCircle className="w-5 h-5 text-red-500" />;
    case 'warning':
      return <AlertCircle className="w-5 h-5 text-yellow-500" />;
    case 'info':
      return <Bell className="w-5 h-5 text-blue-500" />;
    default:
      return <Bell className="w-5 h-5 text-gray-500" />;
  }
};

export default function AlertManagementSystem() {
  const [activeTab, setActiveTab] = useState('dashboard');

  return (
    <div className="p-4 space-y-4">
      <div className="flex gap-4 mb-4">
        <button
          className={`px-4 py-2 rounded ${
            activeTab === 'dashboard' ? 'bg-blue-500 text-white' : 'bg-gray-100'
          }`}
          onClick={() => setActiveTab('dashboard')}
        >
          Dashboard
        </button>
        <button
          className={`px-4 py-2 rounded ${
            activeTab === 'settings' ? 'bg-blue-500 text-white' : 'bg-gray-100'
          }`}
          onClick={() => setActiveTab('settings')}
        >
          Alert Settings
        </button>
      </div>
      
      <AlertDashboard />
    </div>
  );
}
