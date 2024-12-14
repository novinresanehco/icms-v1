import React, { useState, useCallback } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const SecureForm = ({ schema, onSubmit }) => {
  const [formData, setFormData] = useState({});
  const [errors, setErrors] = useState({});

  const validateField = useCallback((name, value) => {
    const rules = schema[name]?.validation || [];
    const fieldErrors = rules
      .map(rule => {
        if (!rule.test(value)) return rule.message;
        return null;
      })
      .filter(Boolean);
    return fieldErrors;
  }, [schema]);

  const handleChange = useCallback((e) => {
    const { name, value } = e.target;
    const fieldErrors = validateField(name, value);
    
    setFormData(prev => ({ ...prev, [name]: value }));
    setErrors(prev => ({ ...prev, [name]: fieldErrors }));
  }, [validateField]);

  const handleSubmit = useCallback((e) => {
    e.preventDefault();
    
    const newErrors = {};
    Object.keys(schema).forEach(field => {
      const fieldErrors = validateField(field, formData[field]);
      if (fieldErrors.length) newErrors[field] = fieldErrors;
    });

    if (Object.keys(newErrors).length === 0) {
      onSubmit(formData);
    } else {
      setErrors(newErrors);
    }
  }, [formData, schema, validateField, onSubmit]);

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {Object.entries(schema).map(([name, field]) => (
        <div key={name} className="space-y-2">
          <label className="block font-medium text-sm text-gray-700">
            {field.label}
          </label>
          <input
            name={name}
            type={field.type}
            value={formData[name] || ''}
            onChange={handleChange}
            className="w-full p-2 border rounded-md"
            required={field.required}
          />
          {errors[name]?.map((error, i) => (
            <Alert key={i} variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          ))}
        </div>
      ))}
      <button 
        type="submit"
        className="w-full py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
      >
        Submit
      </button>
    </form>
  );
};

const DataTable = ({ columns, data, pageSize = 10 }) => {
  const [page, setPage] = useState(0);
  const [sortColumn, setSortColumn] = useState(null);
  const [sortDirection, setSortDirection] = useState('asc');

  const sortedData = useCallback(() => {
    if (!sortColumn) return data;
    return [...data].sort((a, b) => {
      const aVal = a[sortColumn];
      const bVal = b[sortColumn];
      if (sortDirection === 'asc') {
        return aVal > bVal ? 1 : -1;
      }
      return aVal < bVal ? 1 : -1;
    });
  }, [data, sortColumn, sortDirection]);

  const paginatedData = sortedData().slice(
    page * pageSize,
    (page + 1) * pageSize
  );

  const handleSort = (column) => {
    if (sortColumn === column) {
      setSortDirection(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setSortColumn(column);
      setSortDirection('asc');
    }
  };

  return (
    <div className="w-full overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-gray-50">
          <tr>
            {columns.map(column => (
              <th
                key={column.key}
                onClick={() => handleSort(column.key)}
                className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer"
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {paginatedData.map((row, i) => (
            <tr key={i}>
              {columns.map(column => (
                <td key={column.key} className="px-6 py-4 whitespace-nowrap">
                  {column.render ? column.render(row[column.key]) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      
      <div className="flex justify-between items-center mt-4">
        <button
          onClick={() => setPage(p => Math.max(0, p - 1))}
          disabled={page === 0}
          className="px-4 py-2 border rounded-md disabled:opacity-50"
        >
          Previous
        </button>
        <span>{page + 1} / {Math.ceil(data.length / pageSize)}</span>
        <button
          onClick={() => setPage(p => p + 1)}
          disabled={(page + 1) * pageSize >= data.length}
          className="px-4 py-2 border rounded-md disabled:opacity-50"
        >
          Next
        </button>
      </div>
    </div>
  );
};

export { SecureForm, DataTable };
