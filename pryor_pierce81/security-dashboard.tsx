import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Lock, Shield, Users, Key } from 'lucide-react';

const SecurityDashboard = () => {
  // Track active configuration sections
  const [activeTab, setActiveTab] = useState('authentication');

  const metrics = {
    activeUsers: 245,
    failedAttempts: 12,
    securityIncidents: 0,
    averageResponseTime: 89 
  };

  return (
    <div className="w-full max-w-6xl mx-auto p-4">
      <Card className="mb-6">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Shield className="h-6 w-6" />
            Security Control Center
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <Users className="h-8 w-8 text-gray-600" />
                  <div>
                    <p className="text-sm text-gray-600">Active Users</p>
                    <p className="text-2xl font-bold">{metrics.activeUsers}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <Key className="h-8 w-8 text-gray-600" />
                  <div>
                    <p className="text-sm text-gray-600">Failed Attempts</p>
                    <p className="text-2xl font-bold">{metrics.failedAttempts}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <Shield className="h-8 w-8 text-gray-600" />
                  <div>
                    <p className="text-sm text-gray-600">Security Incidents</p>
                    <p className="text-2xl font-bold">{metrics.securityIncidents}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="p-6">
                <div className="flex items-center gap-4">
                  <Lock className="h-8 w-8 text-gray-600" />
                  <div>
                    <p className="text-sm text-gray-600">Avg Response (ms)</p>
                    <p className="text-2xl font-bold">{metrics.averageResponseTime}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        </CardContent>
      </Card>

      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="w-full">
          <TabsTrigger value="authentication">Authentication</TabsTrigger>
          <TabsTrigger value="authorization">Authorization</TabsTrigger>
          <TabsTrigger value="data-protection">Data Protection</TabsTrigger>
          <TabsTrigger value="monitoring">Monitoring</TabsTrigger>
        </TabsList>

        <TabsContent value="authentication">
          <Card>
            <CardHeader>
              <CardTitle>Authentication Settings</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Multi-Factor Authentication</h3>
                    <p className="text-sm text-gray-600">Configure MFA settings and requirements</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Password Policy</h3>
                    <p className="text-sm text-gray-600">Set password requirements and rotation policy</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Session Management</h3>
                    <p className="text-sm text-gray-600">Configure session timeouts and controls</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Login Protection</h3>
                    <p className="text-sm text-gray-600">Set rate limiting and blocking rules</p>
                  </Card>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="authorization">
          <Card>
            <CardHeader>
              <CardTitle>Authorization Controls</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Role Management</h3>
                    <p className="text-sm text-gray-600">Configure roles and permissions hierarchy</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Access Control</h3>
                    <p className="text-sm text-gray-600">Define resource access policies</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">API Security</h3>
                    <p className="text-sm text-gray-600">Manage API access and rate limits</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Audit Logging</h3>
                    <p className="text-sm text-gray-600">Configure security event logging</p>
                  </Card>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="data-protection">
          <Card>
            <CardHeader>
              <CardTitle>Data Protection</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Encryption</h3>
                    <p className="text-sm text-gray-600">Configure data encryption settings</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Backup & Recovery</h3>
                    <p className="text-sm text-gray-600">Manage backup procedures</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Data Retention</h3>
                    <p className="text-sm text-gray-600">Set data lifecycle policies</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Privacy Controls</h3>
                    <p className="text-sm text-gray-600">Configure data privacy settings</p>
                  </Card>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="monitoring">
          <Card>
            <CardHeader>
              <CardTitle>Security Monitoring</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Real-time Monitoring</h3>
                    <p className="text-sm text-gray-600">Configure security monitoring rules</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Alert Configuration</h3>
                    <p className="text-sm text-gray-600">Set up security alert thresholds</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Reporting</h3>
                    <p className="text-sm text-gray-600">Configure security reports and dashboards</p>
                  </Card>
                  <Card className="p-4">
                    <h3 className="font-semibold mb-2">Incident Response</h3>
                    <p className="text-sm text-gray-600">Manage security incident workflows</p>
                  </Card>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default SecurityDashboard;
