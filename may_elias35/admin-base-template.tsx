import React, { useState } from 'react';
import { Menu, Bell, Search, User, Settings, LogOut } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const AdminLayout = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className="min-h-screen bg-gray-100">
      {/* Top Navigation */}
      <nav className="bg-white shadow">
        <div className="px-4 mx-auto max-w-7xl flex justify-between items-center h-16">
          <div className="flex items-center">
            <button onClick={() => setSidebarOpen(!sidebarOpen)} className="p-2">
              <Menu className="h-6 w-6" />
            </button>
            <h1 className="ml-4 text-xl font-bold">CMS Admin</h1>
          </div>
          <div className="flex items-center space-x-4">
            <div className="relative">
              <input
                type="text"
                placeholder="Search..."
                className="w-64 px-4 py-2 rounded-lg border focus:outline-none focus:ring-2"
              />
              <Search className="absolute right-3 top-2.5 h-5 w-5 text-gray-400" />
            </div>
            <button className="relative p-2">
              <Bell className="h-6 w-6" />
              <span className="absolute top-0 right-0 h-2 w-2 bg-red-500 rounded-full"></span>
            </button>
            <div className="relative">
              <button className="flex items-center space-x-2">
                <User className="h-8 w-8 p-1 rounded-full bg-gray-200" />
              </button>
            </div>
          </div>
        </div>
      </nav>

      <div className="flex">
        {/* Sidebar */}
        <aside className={`w-64 bg-white shadow-lg fixed inset-y-0 left-0 transform ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0 lg:static transition-transform duration-200 ease-in-out`}>
          <nav className="mt-16 px-4 space-y-1">
            <SidebarItem href="/admin/dashboard" icon={Menu} active>
              Dashboard
            </SidebarItem>
            <SidebarItem href="/admin/content" icon={Menu}>
              Content
            </SidebarItem>
            <SidebarItem href="/admin/media" icon={Menu}>
              Media
            </SidebarItem>
            <SidebarItem href="/admin/users" icon={Menu}>
              Users
            </SidebarItem>
            <SidebarItem href="/admin/settings" icon={Settings}>
              Settings
            </SidebarItem>
            <SidebarItem href="/logout" icon={LogOut} className="mt-8 text-red-600">
              Logout
            </SidebarItem>
          </nav>
        </aside>

        {/* Main Content */}
        <main className="flex-1 p-6 lg:ml-64">
          <div className="mx-auto max-w-7xl">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
};

const SidebarItem = ({ href, icon: Icon, children, active, className = '' }) => (
  <a
    href={href}
    className={`flex items-center space-x-2 px-4 py-3 rounded-lg transition-colors duration-200 ${
      active ? 'bg-blue-50 text-blue-600' : 'hover:bg-gray-50'
    } ${className}`}
  >
    <Icon className="h-5 w-5" />
    <span>{children}</span>
  </a>
);

const PageHeader = ({ title, actions }) => (
  <div className="mb-8 flex justify-between items-center">
    <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
    <div className="space-x-3">{actions}</div>
  </div>
);

const Card = ({ children, className = '' }) => (
  <div className={`bg-white rounded-lg shadow p-6 ${className}`}>
    {children}
  </div>
);

const LoadingSpinner = () => (
  <div className="flex justify-center items-center h-32">
    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
  </div>
);

const ErrorAlert = ({ message }) => (
  <Alert variant="destructive" className="mb-4">
    <AlertDescription>{message}</AlertDescription>
  </Alert>
);

const ActionButton = ({ 
  children, 
  onClick, 
  variant = 'primary',
  loading = false,
  disabled = false 
}) => {
  const baseStyles = "px-4 py-2 rounded-lg font-medium disabled:opacity-50";
  const variants = {
    primary: "bg-blue-600 text-white hover:bg-blue-700",
    secondary: "bg-gray-200 text-gray-800 hover:bg-gray-300",
    danger: "bg-red-600 text-white hover:bg-red-700"
  };

  return (
    <button
      onClick={onClick}
      disabled={disabled || loading}
      className={`${baseStyles} ${variants[variant]}`}
    >
      {loading ? (
        <div className="flex items-center space-x-2">
          <div className="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></div>
          <span>Loading...</span>
        </div>
      ) : children}
    </button>
  );
};

const Table = ({ columns, data, onRowClick }) => (
  <div className="overflow-x-auto">
    <table className="min-w-full divide-y divide-gray-200">
      <thead className="bg-gray-50">
        <tr>
          {columns.map((column, i) => (
            <th
              key={i}
              className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {column.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody className="bg-white divide-y divide-gray-200">
        {data.map((row, i) => (
          <tr
            key={i}
            onClick={() => onRowClick?.(row)}
            className="hover:bg-gray-50 cursor-pointer"
          >
            {columns.map((column, j) => (
              <td key={j} className="px-6 py-4 whitespace-nowrap">
                {column.render ? column.render(row) : row[column.field]}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

export {
  AdminLayout,
  PageHeader,
  Card,
  LoadingSpinner,
  ErrorAlert,
  ActionButton,
  Table
};

export default AdminLayout;
