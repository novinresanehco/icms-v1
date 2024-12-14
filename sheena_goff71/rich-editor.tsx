import React, { useState } from 'react';
import { Bold, Italic, Link, List, AlertCircle } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

const RichEditor = ({ initialContent = '', onChange, className = '' }) => {
  const [content, setContent] = useState(initialContent);
  const [error, setError] = useState(null);

  const handleCommand = (command) => {
    try {
      document.execCommand(command, false);
      const newContent = document.querySelector('[contenteditable]').innerHTML;
      setContent(newContent);
      onChange?.(newContent);
    } catch (err) {
      setError('Failed to execute editor command');
      console.error(err);
    }
  };

  return (
    <div className={`border rounded-lg overflow-hidden ${className}`}>
      {/* Toolbar */}
      <div className="flex items-center gap-1 p-2 border-b bg-gray-50">
        <button
          onClick={() => handleCommand('bold')}
          className="p-2 rounded hover:bg-gray-200"
          aria-label="Bold"
        >
          <Bold className="w-4 h-4" />
        </button>
        
        <button
          onClick={() => handleCommand('italic')}
          className="p-2 rounded hover:bg-gray-200"
          aria-label="Italic"
        >
          <Italic className="w-4 h-4" />
        </button>
        
        <button
          onClick={() => handleCommand('insertUnorderedList')}
          className="p-2 rounded hover:bg-gray-200"
          aria-label="Bullet list"
        >
          <List className="w-4 h-4" />
        </button>
        
        <button
          onClick={() => {
            const url = window.prompt('Enter link URL');
            if (url) handleCommand('createLink', url);
          }}
          className="p-2 rounded hover:bg-gray-200"
          aria-label="Insert link"
        >
          <Link className="w-4 h-4" />
        </button>
      </div>

      {/* Error Alert */}
      {error && (
        <Alert variant="destructive">
          <AlertCircle className="h-4 w-4" />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {/* Editor Area */}
      <div
        contentEditable
        dangerouslySetInnerHTML={{ __html: content }}
        onInput={(e) => {
          const newContent = e.currentTarget.innerHTML;
          setContent(newContent);
          onChange?.(newContent);
        }}
        className="p-4 min-h-[200px] focus:outline-none"
      />
    </div>
  );
};

export default RichEditor;
