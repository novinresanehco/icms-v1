import React, { useState, useCallback } from 'react';
import { ChevronDown, Layout, Columns, Trash2 } from 'lucide-react';

const TemplateManager = ({ layouts = [], onSelectLayout, activeLayout }) => {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <div className="w-full">
      {/* Layout Selector */}
      <div className="relative">
        <button
          onClick={() => setIsOpen(!isOpen)}
          className="w-full flex items-center justify-between p-4 bg-white border rounded-lg shadow-sm hover:bg-gray-50"
        >
          <div className="flex items-center gap-2">
            <Layout className="w-5 h-5" />
            <span>{activeLayout?.name || 'Select Layout'}</span>
          </div>
          <ChevronDown className={`w-5 h-5 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </button>

        {/* Layout Options */}
        {isOpen && (
          <div className="absolute z-10 w-full mt-2 bg-white border rounded-lg shadow-lg">
            {layouts.map((layout, index) => (
              <button
                key={layout.id || index}
                onClick={() => {
                  onSelectLayout(layout);
                  setIsOpen(false);
                }}
                className="w-full flex items-center gap-3 p-3 hover:bg-gray-50 first:rounded-t-lg last:rounded-b-lg"
              >
                <Columns className="w-5 h-5" />
                <span>{layout.name}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Content Zones */}
      {activeLayout && (
        <div className="mt-6 grid gap-4" style={generateLayoutGrid(activeLayout)}>
          {activeLayout.zones.map((zone, index) => (
            <ContentZone 
              key={zone.id || index}
              zone={zone}
              onDrop={(content) => handleContentDrop(zone.id, content)}
            />
          ))}
        </div>
      )}
    </div>
  );
};

const ContentZone = ({ zone, onDrop }) => {
  const [isDraggingOver, setIsDraggingOver] = useState(false);
  const [content, setContent] = useState(zone.content);

  const handleDragOver = useCallback((e) => {
    e.preventDefault();
    setIsDraggingOver(true);
  }, []);

  const handleDragLeave = useCallback(() => {
    setIsDraggingOver(false);
  }, []);

  const handleDrop = useCallback((e) => {
    e.preventDefault();
    setIsDraggingOver(false);
    const content = JSON.parse(e.dataTransfer.getData('content'));
    setContent(content);
    onDrop(content);
  }, [onDrop]);

  return (
    <div
      onDragOver={handleDragOver}
      onDragLeave={handleDragLeave}
      onDrop={handleDrop}
      className={`
        min-h-[200px] p-4 rounded-lg border-2 
        ${isDraggingOver ? 'border-blue-500 bg-blue-50' : 'border-dashed border-gray-300'}
        ${content ? 'bg-white' : 'bg-gray-50'}
      `}
    >
      {content ? (
        <div className="relative group">
          <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
            <button
              onClick={() => setContent(null)}
              className="p-1 bg-red-500 text-white rounded-full hover:bg-red-600"
              aria-label="Remove content"
            >
              <Trash2 className="w-4 h-4" />
            </button>
          </div>
          <ContentDisplay content={content} />
        </div>
      ) : (
        <div className="h-full flex items-center justify-center text-gray-500">
          <p>Drag content here</p>
        </div>
      )}
    </div>
  );
};

const generateLayoutGrid = (layout) => {
  return {
    gridTemplateAreas: layout.grid,
    gridTemplateColumns: layout.columns,
    gridTemplateRows: layout.rows
  };
};

export default TemplateManager;
