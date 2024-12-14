import React from 'react';
import { createRoot } from 'react-dom/client';
import { X } from 'lucide-react';

const Toast = ({ title, message, type = 'info', duration = 5000, onClose }) => {
  React.useEffect(() => {
    if (duration) {
      const timer = setTimeout(onClose, duration);
      return () => clearTimeout(timer);
    }
  }, [duration, onClose]);

  const getTypeStyles = () => {
    switch (type) {
      case 'success':
        return 'bg-green-100 border-green-500 text-green-800';
      case 'error':
        return 'bg-red-100 border-red-500 text-red-800';
      case 'warning':
        return 'bg-yellow-100 border-yellow-500 text-yellow-800';
      default:
        return 'bg-blue-100 border-blue-500 text-blue-800';
    }
  };

  return (
    <div className={`max-w-md w-full p-4 rounded-lg shadow-lg border-l-4 ${getTypeStyles()}`}>
      <div className="flex justify-between items-start">
        <div className="flex-1">
          {title && (
            <h3 className="font-medium">{title}</h3>
          )}
          <p className="text-sm mt-1">{message}</p>
        </div>
        <button 
          onClick={onClose}
          className="ml-4 p-1 hover:bg-black hover:bg-opacity-10 rounded"
        >
          <X className="w-4 h-4" />
        </button>
      </div>
    </div>
  );
};

const ToastContainer = () => {
  return (
    <div className="fixed top-4 right-4 z-50 space-y-4">
      {/* Toasts will be rendered here */}
    </div>
  );
};

class ToastManager {
  private container: HTMLDivElement;
  private root: any;
  private toasts: Map<string, { element: HTMLDivElement }>;

  constructor() {
    this.toasts = new Map();
    this.createContainer();
  }

  private createContainer() {
    this.container = document.createElement('div');
    this.container.className = 'fixed top-4 right-4 z-50 space-y-4';
    document.body.appendChild(this.container);
    this.root = createRoot(this.container);
  }

  show({ title, message, type = 'info', duration = 5000 }) {
    const id = Math.random().toString(36).substr(2, 9);
    const toastElement = document.createElement('div');
    
    const handleClose = () => {
      this.hide(id);
    };

    const toast = (
      <Toast
        title={title}
        message={message}
        type={type}
        duration={duration}
        onClose={handleClose}
      />
    );

    createRoot(toastElement).render(toast);
    this.container.appendChild(toastElement);
    this.toasts.set(id, { element: toastElement });

    return id;
  }

  hide(id: string) {
    const toast = this.toasts.get(id);
    if (toast) {
      toast.element.remove();
      this.toasts.delete(id);
    }
  }

  success(message: string, title?: string) {
    return this.show({ title, message, type: 'success' });
  }

  error(message: string, title?: string) {
    return this.show({ title, message, type: 'error', duration: 0 });
  }

  warning(message: string, title?: string) {
    return this.show({ title, message, type: 'warning' });
  }

  info(message: string, title?: string) {
    return this.show({ title, message, type: 'info' });
  }

  clearAll() {
    this.toasts.forEach((toast, id) => {
      this.hide(id);
    });
  }
}

export const toast = new ToastManager();
export { Toast, ToastContainer };