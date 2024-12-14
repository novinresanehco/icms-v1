import React, { useState, useEffect } from 'react';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend } from 'recharts';
import { Bell, Database, Memory, Clock } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';

const MonitoringDashboard = () => {
    const [metrics, setMetrics] = useState(null);
    const [alerts, setAlerts] = useState([]);
    const [timeframe, setTimeframe] = useState('1h');

    useEffect(() => {
        fetchMetrics();
        const interval = setInterval(fetchMetrics, 5000);
        return () => clearInterval(interval);
    }, [timeframe]);

    const fetchMetrics = async () => {
        // In real implementation, this would fetch from your API
        const data = await fetch(`/api/monitoring/metrics?timeframe=${timeframe}`);
        setMetrics(await data.json());
    };

    if (!metrics) return <div className="flex items-center justify-center p-8">Loading metrics...</div>;

    return (
        <div className="p-6 space-y-6">
            {/* Header */}
            <div className="flex justify-between items-center">
                <h1 className="text-2xl font-bold">System Monitoring</h1>
                <select 
                    className="p-2 border rounded"
                    value={timeframe}
                    onChange={(e) => setTimeframe(e.target.value)}
                >
                    <option value="1h">Last Hour</option>
                    <option value="6h">Last 6 Hours</option>
                    <option value="24h">Last 24 Hours</option>
                </select>
            </div>

            {/* Key Metrics */}
            <div className="grid grid-cols-4 gap-4">
                <MetricCard
                    icon={<Clock />}
                    title="Avg Response Time"
                    value={`${metrics.overview.avg_response_time.toFixed(2)}ms`}
                    trend={metrics.overview.response_time_trend}
                />
                <MetricCard
                    icon={<Database />}
                    title="Query Count"
                    value={metrics.overview.total_queries}
                    trend={metrics.overview.query_count_trend}
                />
                <MetricCard
                    icon={<Memory />}
                    title="Memory Usage"
                    value={`${(metrics.overview.avg_memory / 1024 / 1024).toFixed(2)}MB`}
                    trend={metrics.overview.memory_trend}
                />
                <MetricCard
                    icon={<Bell />}
                    title="Active Alerts"
                    value={alerts.length}
                    status={alerts.length > 0 ? 'warning' : 'success'}
                />
            </div>

            {/* Performance Charts */}
            <div className="grid grid-cols-2 gap-4">
                <div className="p-4 bg-white rounded-lg shadow">
                    <h3 className="text-lg font-semibold mb-4">Response Time Trend</h3>
                    <LineChart width={500} height={300} data={metrics.trends.response_time}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis dataKey="timestamp" />
                        <YAxis />
                        <Tooltip />
                        <Legend />
                        <Line type="monotone" dataKey="value" stroke="#8884d8" name="Response Time (ms)" />
                    </LineChart>
                </div>

                <div className="p-4 bg-white rounded-lg shadow">
                    <h3 className="text-lg font-semibold mb-4">Memory Usage Trend</h3>
                    <LineChart width={500} height={300} data={metrics.trends.memory_usage}>
                        <CartesianGrid strokeDasharray="3 3" />
                        <XAxis dataKey="timestamp" />
                        <YAxis />
                        <Tooltip />
                        <Legend />
                        <Line type="monotone" dataKey="value" stroke="#82ca9d" name="Memory (MB)" />
                    </LineChart>
                </div>
            </div>

            {/* Active Alerts */}
            <div className="space-y-4">
                <h3 className="text-lg font-semibold">Active Alerts</h3>
                {alerts.length === 0 ? (
                    <div className="text-green-600">No active alerts</div>
                ) : (
                    <div className="space-y-2">
                        {alerts.map((alert, index) => (
                            <Alert key={index} variant={alert.severity}>
                                <AlertTitle>{alert.title}</AlertTitle>
                                <AlertDescription>{alert.message}</AlertDescription>
                            </Alert>
                        ))}
                    </div>
                )}
            </div>

            {/* Recent Operations */}
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
                        {metrics.recent_operations.map((op, index) => (
                            <tr key={index} className="border-t">
                                <td className="p-2">{op.operation}</td>
                                <td className="p-2">{op.duration}ms</td>
                                <td className="p-2">{op.query_count}</td>
                                <td className="p-2">{(op.memory_used / 1024 / 1024).toFixed(2)}MB</td>
                                <td className="p-2">
                                    <span className={`px-2 py-1 rounded text-sm ${
                                        op.status === 'success' ? 'bg-green-100 text-green-800' :
                                        op.status === 'warning' ? 'bg-yellow-100 text-yellow-800' :
                                        'bg-red-100 text-red-800'
                                    }`}>
                                        {op.status}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

const MetricCard = ({ icon, title, value, trend, status = 'normal' }) => (
    <div className="p-4 bg-white rounded-lg shadow">
        <div className="flex items-center justify-between">
            <div className="flex items-center">
                <div className="p-2 rounded-lg bg-blue-100">{icon}</div>
                <div className="ml-3">
                    <h3 className="text-sm font-medium text-gray-500">{title}</h3>
                    <p className="text-lg font-semibold">{value}</p>
                </div>
            </div>
            {trend && (
                <div className={`text-sm ${
                    trend > 0 ? 'text-green-600' : 'text-red-600'
                }`}>
                    {trend > 0 ? '↑' : '↓'} {Math.abs(trend)}%
                </div>
            )}
        </div>
    </div>
);

export default MonitoringDashboard;
