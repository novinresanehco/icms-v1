import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { Users, Eye, ArrowUp, ArrowDown, Clock, Globe } from 'lucide-react';

const visitorData = [
  { date: '1402/12/01', visitors: 1240, pageViews: 3800 },
  { date: '1402/12/02', visitors: 1435, pageViews: 4200 },
  { date: '1402/12/03', visitors: 1890, pageViews: 5100 },
  { date: '1402/12/04', visitors: 1684, pageViews: 4700 },
  { date: '1402/12/05', visitors: 2010, pageViews: 5400 },
  { date: '1402/12/06', visitors: 1852, pageViews: 5000 },
  { date: '1402/12/07', visitors: 2120, pageViews: 5800 }
];

const pageData = [
  { page: '/blog', views: 2840, bounce: 42 },
  { page: '/products', views: 2380, bounce: 35 },
  { page: '/about', views: 1920, bounce: 48 },
  { page: '/contact', views: 1640, bounce: 52 },
  { page: '/services', views: 1480, bounce: 44 }
];

const AnalyticsDashboard = () => {
  return (
    <div className="w-full space-y-6">
      {/* Key Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardContent className="p-6">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-sm text-gray-500">بازدیدکنندگان امروز</p>
                <h3 className="text-2xl font-bold mt-1">2,120</h3>
                <span className="text-sm text-green-500 flex items-center mt-1">
                  <ArrowUp className="w-4 h-4 mr-1" />
                  14.5%
                </span>
              </div>
              <Users className="w-8 h-8 text-blue-500" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-sm text-gray-500">بازدید صفحات</p>
                <h3 className="text-2xl font-bold mt-1">5,800</h3>
                <span className="text-sm text-green-500 flex items-center mt-1">
                  <ArrowUp className="w-4 h-4 mr-1" />
                  8.2%
                </span>
              </div>
              <Eye className="w-8 h-8 text-indigo-500" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-sm text-gray-500">میانگین زمان بازدید</p>
                <h3 className="text-2xl font-bold mt-1">4:25</h3>
                <span className="text-sm text-red-500 flex items-center mt-1">
                  <ArrowDown className="w-4 h-4 mr-1" />
                  2.1%
                </span>
              </div>
              <Clock className="w-8 h-8 text-green-500" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex justify-between items-start">
              <div>
                <p className="text-sm text-gray-500">نرخ پرش</p>
                <h3 className="text-2xl font-bold mt-1">42.5%</h3>
                <span className="text-sm text-green-500 flex items-center mt-1">
                  <ArrowUp className="w-4 h-4 mr-1" />
                  3.4%
                </span>
              </div>
              <Globe className="w-8 h-8 text-purple-500" />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>روند بازدید</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <LineChart width={500} height={300} data={visitorData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="date" />
                <YAxis />
                <Tooltip />
                <Line type="monotone" dataKey="visitors" stroke="#3B82F6" name="بازدیدکنندگان" />
                <Line type="monotone" dataKey="pageViews" stroke="#10B981" name="بازدید صفحات" />
              </LineChart>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>صفحات پربازدید</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <BarChart width={500} height={300} data={pageData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="page" />
                <YAxis />
                <Tooltip />
                <Bar dataKey="views" fill="#3B82F6" name="تعداد بازدید" />
              </BarChart>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Top Pages Table */}
      <Card>
        <CardHeader>
          <CardTitle>عملکرد صفحات</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">صفحه</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">بازدید</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">نرخ پرش</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عملکرد</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {pageData.map((page, index) => (
                  <tr key={index}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{page.page}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{page.views.toLocaleString()}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">{page.bounce}%</td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="w-full bg-gray-200 rounded-full h-2">
                        <div 
                          className="bg-blue-500 h-2 rounded-full" 
                          style={{ width: `${(page.views / 3000) * 100}%` }}
                        ></div>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default AnalyticsDashboard;
