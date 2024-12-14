import React, { useState, useCallback } from 'react';
import { ChevronDown } from 'lucide-react';

// Display Manager handles content layout and rendering
export default function DisplayManager({
  content = {},
  template = 'default',
  onTemplateChange,
  className = ''
}) {
  const [isEditing, setIsEditing] = useState(false);

  // Template Selection Handler
  const handleTemplateSelect = useCallback((newTemplate) => {
    onTemplateChange?.(newTemplate);
    setIsEditing(false);
  }, [onTemplateChange]);

  return (
    <div className={className}>
      {/* Template Controls */}
      <div className="mb-4 flex items-center gap-4">
        <div className="relative">
          <button
            onClick={() => setIsEditing(!isEditing)}
            className="flex items-center gap-2 px-4 py-2 bg-white border rounded-lg"
          >
            <span>Template: {template}</span>
            <ChevronDown className="w-4 h-4" />
          </button>

          {isEditing && (
            <div className="absolute top-full mt-1 w-48 bg-white border rounded-lg shadow-lg">
              <button
                onClick={() => handleTemplateSelect('default')}
                className="block w-full px-4 py-2 text-left hover:bg-gray-50"
              >
                Default
              </button>
              <button
                onClick={() => handleTemplateSelect('twoColumn')}
                className="block w-full px-4 py-2 text-left hover:bg-gray-50"
              >
                Two Column
              </button>
              <button
                onClick={() => handleTemplateSelect('fullWidth')}
                className="block w-full px-4 py-2 text-left hover:bg-gray-50"
              >
                Full Width
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Content Display */}
      <Template
        type={template}
        content={content}
        className="min-h-[400px]"
      />
    </div>
  );
}
